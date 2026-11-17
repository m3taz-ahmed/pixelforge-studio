<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\GenerationSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a recorded webhook event to the matching generation. Kept off the
 * request path so the endpoint answers 2xx immediately; the dedupe insert in
 * the controller means this job can only ever exist once per event id.
 */
class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $eventId,
    ) {}

    public function backoff(): array
    {
        return [2, 5, 15];
    }

    public function handle(GenerationSync $sync): void
    {
        $event = WebhookEvent::query()
            ->where('event_id', $this->eventId)
            ->whereNull('processed_at')
            ->first();

        if (! $event instanceof WebhookEvent) {
            return;
        }

        $object = $event->payload['data']['object'] ?? null;

        if (! is_array($object)) {
            $event->update(['processed_at' => now()]);

            return;
        }

        if (! $sync->apply($object)) {
            // The local generation is not there yet — e.g. a webhook beat our
            // own POST bookkeeping. Retry shortly; the reconcile job is the
            // final safety net.
            throw new \RuntimeException("Generation for event {$this->eventId} not found yet.");
        }

        $event->update(['processed_at' => now()]);
    }
}
