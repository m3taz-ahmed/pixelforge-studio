<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Receives Pixelforge webhook events.
 *
 * Registered outside the `web` middleware group (no CSRF/session — see
 * bootstrap/app.php). The handler only verifies the signature on the raw
 * body and records the event; the real work runs in ProcessPixelforgeWebhook
 * so we always answer 2xx fast, well inside the 10s delivery window.
 *
 * Dedupe is the unique index on webhook_events.event_id: a delivery that
 * loses the insert race is acknowledged but never processed twice.
 */
class PixelforgeWebhookController extends Controller
{
    private const TIMESTAMP_TOLERANCE = 300;

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();

        if (! $this->signatureValid($request, $payload)) {
            return response('Invalid signature.', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response('Malformed event.', 400);
        }

        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'event_id' => (string) $event['id'],
            'type' => (string) $event['type'],
            'generation_provider_id' => $event['data']['object']['id'] ?? null,
            'payload' => json_encode($event),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted > 0) {
            ProcessPixelforgeWebhook::dispatch((string) $event['id']);
        }

        return response()->noContent();
    }

    /**
     * Signature: HMAC-SHA256 of "<timestamp>.<raw body>" keyed by the key's
     * whsec_ secret, compared in constant time. Timestamps older than five
     * minutes are rejected to stop replayed deliveries.
     */
    private function signatureValid(Request $request, string $payload): bool
    {
        $header = (string) $request->header('Pixelforge-Signature');
        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = (string) ($parts['v1'] ?? '');

        if ($timestamp === 0 || $signature === '') {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            "{$timestamp}.{$payload}",
            (string) config('services.pixelforge.webhook_secret')
        );

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        return abs(time() - $timestamp) <= self::TIMESTAMP_TOLERANCE;
    }
}
