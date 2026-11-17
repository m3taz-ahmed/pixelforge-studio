<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a provider generation object (webhook payload, GET response, or the
 * 202 from POST /generations) to the local row. This is the single place where
 * provider state becomes local state, so the state machine lives exactly once:
 *
 *   pending -> queued -> processing -> succeeded | failed
 *
 * Rules:
 *  - A generation is matched by provider_id, falling back to metadata.local_id
 *    (webhooks can arrive before our POST response stored the provider id).
 *  - Status can only move forward. Terminal statuses are sticky — a late or
 *    duplicated event never rewinds a finished generation.
 *  - Billing happens inside the transition to `succeeded`, guarded by a
 *    conditional update, and the charge row is unique per generation — so a
 *    user is billed exactly once no matter how many times the event arrives.
 */
class GenerationSync
{
    /**
     * @param  array<string, mixed>  $object  Provider generation object.
     * @return bool False when no local generation could be matched (caller may retry later).
     */
    public function apply(array $object): bool
    {
        $generation = $this->findGeneration($object);

        if (! $generation instanceof Generation) {
            return false;
        }

        $incoming = (string) ($object['status'] ?? '');

        if (Generation::statusRank($incoming) < 0) {
            return true;
        }

        if (Generation::statusRank($incoming) <= Generation::statusRank($generation->status)) {
            $this->backfillIdentity($generation, $object);

            return true;
        }

        if ($incoming === Generation::STATUS_SUCCEEDED) {
            $this->settle($generation, $object);
        } elseif ($incoming === Generation::STATUS_FAILED) {
            $this->markFailed($generation, $object);
        } else {
            $generation->update(array_filter([
                'provider_id' => $generation->provider_id ?? $object['id'],
                'status' => $incoming,
                'rates' => $generation->rates ?? ($object['rates'] ?? null),
            ], fn ($value) => $value !== null));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function findGeneration(array $object): ?Generation
    {
        $providerId = $object['id'] ?? null;

        if (is_string($providerId)) {
            $generation = Generation::query()->where('provider_id', $providerId)->first();

            if ($generation instanceof Generation) {
                return $generation;
            }
        }

        $localId = $object['metadata']['local_id'] ?? null;

        if (is_string($localId)) {
            return Generation::query()->find($localId);
        }

        return null;
    }

    /**
     * Older/equal events still carry information we might not have yet
     * (provider id, rates). Fill gaps without touching status or money.
     *
     * @param  array<string, mixed>  $object
     */
    private function backfillIdentity(Generation $generation, array $object): void
    {
        $dirty = [];

        if ($generation->provider_id === null && isset($object['id'])) {
            $dirty['provider_id'] = $object['id'];
        }

        if ($generation->rates === null && isset($object['rates'])) {
            $dirty['rates'] = $object['rates'];
        }

        if ($dirty !== []) {
            $generation->fill($dirty)->save();
        }
    }

    /**
     * Bill exactly once: the conditional UPDATE only succeeds for the first
     * process to reach `succeeded`, and credit_transactions.generation_id is
     * unique as a second line of defence.
     *
     * @param  array<string, mixed>  $object
     */
    private function settle(Generation $generation, array $object): void
    {
        $usage = is_array($object['usage'] ?? null) ? $object['usage'] : [];
        $rates = $generation->rates ?? $object['rates'] ?? [];

        $providerCost = Pricing::providerCost($usage, is_array($rates) ? $rates : []);
        $userCharge = Pricing::userCharge($providerCost);

        DB::transaction(function () use ($generation, $object, $usage, $providerCost, $userCharge): void {
            // Terminal statuses are excluded, not just `succeeded`: a concurrent
            // markFailed must never be flipped back to succeeded by this claim.
            $settled = Generation::query()
                ->whereKey($generation->id)
                ->whereNotIn('status', Generation::TERMINAL_STATUSES)
                ->whereNull('provider_cost')
                ->update([
                    'provider_id' => $object['id'],
                    'status' => Generation::STATUS_SUCCEEDED,
                    'usage' => $usage,
                    'provider_cost' => (string) $providerCost,
                    'user_charge' => (string) $userCharge,
                    'image_url' => $object['image_url'] ?? null,
                    'image_url_expires_at' => isset($object['image_url_expires_at'])
                        ? Carbon::parse($object['image_url_expires_at'])
                        : null,
                    'completed_at' => isset($object['completed_at'])
                        ? Carbon::parse($object['completed_at'])
                        : now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);

            if ($settled === 0) {
                return;
            }

            $user = User::query()->whereKey($generation->user_id)->lockForUpdate()->first();

            if (! $user instanceof User) {
                return;
            }

            // Authorize/capture: the worst-case hold taken at submit is fully
            // released, then the *actual* charge is taken — net effect is the
            // user pays exactly user_charge, never the estimate.
            $balanceAfter = $this->releaseLocked($generation->id, $user)->minus($userCharge);

            $user->balance = (string) $balanceAfter;
            $user->save();

            CreditTransaction::query()->create([
                'user_id' => $user->id,
                'generation_id' => $generation->id,
                'type' => CreditTransaction::TYPE_CHARGE,
                'amount' => (string) $userCharge->negated(),
                'balance_after' => (string) $balanceAfter,
                'memo' => 'Image generation charge',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function markFailed(Generation $generation, array $object): void
    {
        $error = is_array($object['error'] ?? null) ? $object['error'] : [];

        DB::transaction(function () use ($generation, $object, $error): void {
            $updated = Generation::query()
                ->whereKey($generation->id)
                ->whereNotIn('status', Generation::TERMINAL_STATUSES)
                ->update([
                    'provider_id' => $generation->provider_id ?? $object['id'],
                    'status' => Generation::STATUS_FAILED,
                    'error_code' => $error['code'] ?? 'generation_failed',
                    'error_message' => $error['message'] ?? 'The provider failed this generation.',
                    'completed_at' => isset($object['completed_at'])
                        ? Carbon::parse($object['completed_at'])
                        : now(),
                ]);

            if ($updated === 0) {
                return;
            }

            $user = User::query()->whereKey($generation->user_id)->lockForUpdate()->first();

            if ($user instanceof User) {
                $this->releaseLocked($generation->id, $user);
            }
        });
    }

    /**
     * Failure that happens outside the provider lifecycle — rejected submit,
     * or a row that can never settle. Marks the generation failed if it is
     * still open and releases its credit hold, atomically; safe to call on
     * an already-terminal row (it becomes a no-op).
     */
    public function failLocally(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message): void {
            $updated = Generation::query()
                ->whereKey($generation->id)
                ->whereNotIn('status', Generation::TERMINAL_STATUSES)
                ->update([
                    'status' => Generation::STATUS_FAILED,
                    'error_code' => $code,
                    'error_message' => $message,
                    'completed_at' => now(),
                ]);

            if ($updated === 0) {
                return;
            }

            $user = User::query()->whereKey($generation->user_id)->lockForUpdate()->first();

            if ($user instanceof User) {
                $this->releaseLocked($generation->id, $user);
            }
        });
    }

    /**
     * Give a held amount back to the user. Must be called inside a transaction
     * after the caller's own state-transition claim has succeeded — `held_amount`
     * is nulled on release and (generation_id, type) is unique, so the money
     * can only ever be returned once. Returns the balance after release.
     */
    private function releaseLocked(string $generationId, User $user): BigDecimal
    {
        $balance = $user->creditBalance();

        $generation = Generation::query()->whereKey($generationId)->lockForUpdate()->first();

        if (! $generation instanceof Generation || $generation->held_amount === null) {
            return $balance;
        }

        $held = BigDecimal::of((string) $generation->held_amount);
        $generation->held_amount = null;
        $generation->save();

        $balance = $balance->plus($held);
        $user->balance = (string) $balance;
        $user->save();

        CreditTransaction::query()->create([
            'user_id' => $user->id,
            'generation_id' => $generation->id,
            'type' => CreditTransaction::TYPE_RELEASE,
            'amount' => (string) $held,
            'balance_after' => (string) $balance,
            'memo' => 'Credit hold released',
        ]);

        return $balance;
    }
}
