<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationSync;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;

/**
 * Polling fallback for missed webhooks (delivery is at-least-once but not
 * guaranteed). Runs every minute via the scheduler:
 *  - `pending` rows whose create call never reached the provider are
 *    re-submitted with the same payload + Idempotency-Key (safe replay).
 *  - open rows (`queued`/`processing`) are re-fetched and fed through the
 *    same sync path as webhooks.
 *  - unprocessed webhook_events get one more shot through the normal job.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile';

    protected $description = 'Re-check open generations against Pixelforge and replay unprocessed webhook events.';

    public function handle(PixelforgeClient $client, GenerationSync $sync): int
    {
        $this->replayUnprocessedEvents();

        Generation::query()
            ->where('status', Generation::STATUS_PENDING)
            ->whereNull('provider_id')
            ->where('created_at', '<=', now()->subSeconds(15))
            ->limit(10)
            ->get()
            ->each(fn (Generation $generation) => $this->resubmit($client, $sync, $generation));

        Generation::query()
            ->whereIn('status', [Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING])
            ->whereNotNull('provider_id')
            ->where('created_at', '<=', now()->subSeconds(20))
            ->limit(10)
            ->get()
            ->each(fn (Generation $generation) => $this->refresh($client, $sync, $generation));

        return self::SUCCESS;
    }

    private function replayUnprocessedEvents(): void
    {
        \App\Models\WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '<=', now()->subMinutes(1))
            ->limit(20)
            ->pluck('event_id')
            ->each(fn (string $eventId) => \App\Jobs\ProcessPixelforgeWebhook::dispatch($eventId));
    }

    private function resubmit(PixelforgeClient $client, GenerationSync $sync, Generation $generation): void
    {
        try {
            $object = $client->createGeneration(
                $generation->request_payload, $generation->idempotency_key
            );
            $sync->apply($object);
        } catch (PixelforgeException $e) {
            if ($e->isRejected()) {
                $generation->update([
                    'status' => Generation::STATUS_FAILED,
                    'error_code' => $e->errorCode ?? 'submit_failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
            }
            // Connection errors leave the row pending for the next sweep.
        }
    }

    private function refresh(PixelforgeClient $client, GenerationSync $sync, Generation $generation): void
    {
        try {
            $object = $client->getGeneration($generation->provider_id);
        } catch (PixelforgeException) {
            return;
        }

        if (is_array($object)) {
            $sync->apply($object);
        }
    }
}
