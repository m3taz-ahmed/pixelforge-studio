<?php

namespace Tests\Feature;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_testsecret123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.webhook_secret' => self::SECRET]);
    }

    /** @param array<string, mixed> $payload */
    private function postEvent(array $payload, ?string $secret = self::SECRET, ?int $timestamp = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = $secret === null
            ? 'v1=invalid'
            : hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Pixelforge-Event-Id' => $payload['id'] ?? '',
            'HTTP_Pixelforge-Event-Type' => $payload['type'] ?? '',
            'HTTP_Pixelforge-Signature' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function providerObject(Generation $generation, array $overrides = []): array
    {
        return array_merge([
            'id' => $generation->provider_id ?? 'gen_01TEST',
            'object' => 'generation',
            'status' => 'processing',
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => [],
            'metadata' => ['local_id' => $generation->id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'created_at' => now()->toIso8601String(),
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $object @return array<string, mixed> */
    private function event(array $object, string $type, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'data' => ['object' => $object],
        ];
    }

    private function makeGeneration(array $overrides = []): Generation
    {
        return Generation::query()->create(array_merge([
            'user_id' => User::factory()->create(['balance' => '10.000000000'])->id,
            'provider_id' => 'gen_01TEST',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'request_payload' => [],
            'status' => Generation::STATUS_PROCESSING,
            'estimated_cost' => '0.060000000',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ], $overrides));
    }

    private function succeededObject(Generation $generation): array
    {
        return $this->providerObject($generation, [
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01TEST.png?signature=x',
            'image_url_expires_at' => now()->addDay()->toIso8601String(),
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 1000,
                'total_tokens' => 2112,
            ],
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function test_rejects_unsigned_and_forged_requests(): void
    {
        $this->postJson('/webhooks/pixelforge', ['id' => 'evt_x'])->assertStatus(400);
        $this->postEvent($this->event([], 'ping'), secret: 'whsec_wrong')->assertStatus(400);
        $this->postEvent($this->event([], 'ping'), timestamp: time() - 400)->assertStatus(400);
    }

    public function test_valid_succeeded_event_settles_and_charges_once(): void
    {
        $generation = $this->makeGeneration();

        $this->postEvent($this->event($this->succeededObject($generation), 'generation.succeeded'))
            ->assertNoContent();

        $generation->refresh();

        // 12*5 + 1100*40 + 1000*12.5 = 60 + 44000 + 12500 = 56560 per 1M
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.056560000', $generation->provider_cost);
        $this->assertSame('0.070700000', $generation->user_charge);
        $this->assertSame('9.929300000', (string) $generation->user->refresh()->balance);

        $this->assertSame(1, CreditTransaction::query()->where('generation_id', $generation->id)->count());
        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertNotNull(WebhookEvent::query()->first()->processed_at);
    }

    public function test_duplicate_delivery_is_recorded_once_and_charged_once(): void
    {
        $generation = $this->makeGeneration();
        $event = $this->event($this->succeededObject($generation), 'generation.succeeded');

        $this->postEvent($event)->assertNoContent();
        $this->postEvent($event)->assertNoContent();

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, CreditTransaction::query()->count());
        $this->assertSame('9.929300000', (string) $generation->user->refresh()->balance);
    }

    public function test_distinct_event_ids_still_cannot_double_charge(): void
    {
        $generation = $this->makeGeneration();

        $this->postEvent($this->event($this->succeededObject($generation), 'generation.succeeded'))
            ->assertNoContent();
        $this->postEvent($this->event($this->succeededObject($generation), 'generation.succeeded'))
            ->assertNoContent();

        $this->assertSame(2, WebhookEvent::query()->count());
        $this->assertSame(1, CreditTransaction::query()->count());
        $this->assertSame('0.070700000', $generation->refresh()->user_charge);
    }

    public function test_out_of_order_events_never_regress_terminal_state(): void
    {
        $generation = $this->makeGeneration();

        $this->postEvent($this->event($this->succeededObject($generation), 'generation.succeeded'));
        $this->postEvent($this->event($this->providerObject($generation), 'generation.processing'));

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->refresh()->status);
    }

    public function test_early_webhook_matches_on_metadata_before_provider_id_is_stored(): void
    {
        $generation = $this->makeGeneration(['provider_id' => null, 'status' => Generation::STATUS_PENDING]);
        $object = $this->providerObject($generation, ['id' => 'gen_LATE', 'status' => 'processing']);

        $this->postEvent($this->event($object, 'generation.processing'))->assertNoContent();

        $generation->refresh();
        $this->assertSame('gen_LATE', $generation->provider_id);
        $this->assertSame(Generation::STATUS_PROCESSING, $generation->status);
    }

    public function test_failed_generation_costs_nothing(): void
    {
        $generation = $this->makeGeneration();
        $object = $this->providerObject($generation, [
            'status' => 'failed',
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
            'completed_at' => now()->toIso8601String(),
        ]);

        $this->postEvent($this->event($object, 'generation.failed'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->user_charge);
        $this->assertSame(0, CreditTransaction::query()->count());
        $this->assertSame('10.000000000', (string) $generation->user->refresh()->balance);
    }
}
