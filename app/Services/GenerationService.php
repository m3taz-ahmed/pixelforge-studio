<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
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

        if ($user->creditBalance()->isLessThan($estimate['max'])) {
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
            'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => $params['prompt'],
            'size' => $params['size'],
            'quality' => $params['quality'],
            'reference_images' => $params['reference_images'],
            'request_payload' => $payload,
            'status' => Generation::STATUS_PENDING,
            'estimated_cost' => $estimate['estimated'],
        ])->save();

        try {
            $object = $this->client->createGeneration($payload, $generation->idempotency_key);
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
            // Network-level failure: the row stays `pending` and the reconcile
            // job replays the create with the same Idempotency-Key.
            return;
        }

        $generation->update([
            'status' => Generation::STATUS_FAILED,
            'error_code' => $e->errorCode ?? 'submit_failed',
            'error_message' => $e->getMessage(),
            'completed_at' => now(),
        ]);

        $field = $e->errorCode === 'content_policy_violation' ? 'prompt' : 'credits';

        $message = $e->getMessage();

        if (! str_contains($message, 'not charged')) {
            $message .= ' You were not charged.';
        }

        throw ValidationException::withMessages([$field => $message]);
    }
}
