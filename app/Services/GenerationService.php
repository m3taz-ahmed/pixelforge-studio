<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Submits a generation request to Pixelforge.
 *
 * Order of operations is deliberate: the local row is written *before* the
 * provider call, with our UUID echoed in metadata.local_id — a webhook can
 * legitimately arrive before POST returns, and it must find a row to match.
 * If the HTTP call itself dies (connection error), the row stays `pending`
 * and the reconcile job re-sends the same payload + Idempotency-Key, which
 * the provider replays safely instead of double-creating.
 *
 * Credits use authorize/capture: the worst-case estimate (`max`) is held at
 * submit inside a row-locked transaction — concurrent submits can't both
 * spend the same balance. On settle the hold is fully released and only the
 * actual charge is taken; on any failure the hold is released untouched.
 */
class GenerationService
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationSync $sync,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: array<int, string>}  $params
     *
     * @throws ValidationException
     */
    public function submit(User $user, array $params): Generation
    {
        $estimate = Pricing::estimate(
            $params['prompt'], $params['size'], $params['quality'], $params['reference_images']
        );

        $generation = DB::transaction(function () use ($user, $params, $estimate): Generation {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->creditBalance()->isLessThan($estimate['max'])) {
                throw ValidationException::withMessages([
                    'credits' => "Insufficient credits: this generation could cost up to \${$estimate['max']}. Please buy credits first.",
                ]);
            }

            $generation = new Generation;
            $generation->id = (string) Str::uuid();

            $payload = [
                'prompt' => $params['prompt'],
                'size' => $params['size'],
                'quality' => $params['quality'],
                'metadata' => ['local_id' => $generation->id],
            ];

            if ($params['reference_images'] !== []) {
                $payload['reference_images'] = array_values($params['reference_images']);
            }

            $generation->forceFill([
                'user_id' => $lockedUser->id,
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $params['prompt'],
                'size' => $params['size'],
                'quality' => $params['quality'],
                'reference_images' => $params['reference_images'],
                'request_payload' => $payload,
                'status' => Generation::STATUS_PENDING,
                'estimated_cost' => $estimate['estimated'],
                'held_amount' => $estimate['max'],
            ])->save();

            $balanceAfter = $lockedUser->creditBalance()->minus($estimate['max']);
            $lockedUser->balance = (string) $balanceAfter;
            $lockedUser->save();

            CreditTransaction::query()->create([
                'user_id' => $lockedUser->id,
                'generation_id' => $generation->id,
                'type' => CreditTransaction::TYPE_HOLD,
                'amount' => (string) BigDecimal::of($estimate['max'])->negated(),
                'balance_after' => (string) $balanceAfter,
                'memo' => 'Credit hold — worst-case price for generation',
            ]);

            return $generation;
        });

        try {
            $object = $this->client->createGeneration($generation->request_payload, $generation->idempotency_key);
            $this->sync->apply($object);
        } catch (PixelforgeException $e) {
            $this->handleSubmitFailure($generation, $e);
        }

        return $generation->refresh();
    }

    /**
     * @throws ValidationException
     */
    private function handleSubmitFailure(Generation $generation, PixelforgeException $e): void
    {
        if (! $e->isRejected()) {
            // Network-level failure: the row stays `pending` (hold stays
            // reserved) and the reconcile job replays the create with the
            // same Idempotency-Key.
            return;
        }

        $this->sync->failLocally($generation, $e->errorCode ?? 'submit_failed', $e->getMessage());

        $field = $e->errorCode === 'content_policy_violation' ? 'prompt' : 'credits';

        $message = $e->getMessage();

        if (! str_contains($message, 'not charged')) {
            $message .= ' You were not charged.';
        }

        throw ValidationException::withMessages([$field => $message]);
    }
}
