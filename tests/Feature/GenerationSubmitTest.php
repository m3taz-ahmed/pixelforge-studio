<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerationSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Retries must not actually sleep in tests.
        $this->app->bind(\App\Services\Pixelforge\PixelforgeClient::class, function (): \App\Services\Pixelforge\PixelforgeClient {
            $client = \App\Services\Pixelforge\PixelforgeClient::fromConfig();
            $client->setSleeper(fn (): null => null);

            return $client;
        });
    }

    private function fakeProvider(array $createResponse = [], int $status = 202): void
    {
        Http::fake([
            '*/v1/pricing' => Http::response([
                'object' => 'pricing',
                'currency' => 'usd',
                'unit' => 'per_1m_tokens',
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'tokens' => [
                    'characters_per_text_token' => 4,
                    'text_token_variance' => 0.15,
                    'per_reference_image' => 765,
                    'output' => [
                        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                        'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                        'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                    ],
                    'reasoning' => [
                        'low' => ['min' => 0, 'max' => 500],
                        'medium' => ['min' => 200, 'max' => 2000],
                        'high' => ['min' => 1000, 'max' => 8000],
                    ],
                ],
            ]),
            '*/v1/generations' => function (\Illuminate\Http\Client\Request $request) use ($createResponse, $status) {
                return Http::response($createResponse ?: [
                    'id' => 'gen_FAKE1',
                    'object' => 'generation',
                    'status' => 'queued',
                    'metadata' => $request['metadata'] ?? [],
                    'rates' => [
                        'input_text_tokens' => '5.00',
                        'input_image_tokens' => '10.00',
                        'output_tokens' => '40.00',
                        'reasoning_tokens' => '12.50',
                    ],
                ], $status);
            },
        ]);
    }

    private function params(array $overrides = []): array
    {
        return array_merge([
            'prompt' => 'a lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], $overrides);
    }

    public function test_submit_creates_local_record_then_calls_provider_with_idempotency(): void
    {
        $this->fakeProvider();
        $user = User::factory()->create(['balance' => '10.000000000']);

        $this->actingAs($user)->post(route('generations.store'), $this->params())
            ->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();

        $this->assertSame('gen_FAKE1', $generation->provider_id);
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame('5.00', $generation->rates['input_text_tokens']);
        $this->assertNotNull($generation->estimated_cost);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($generation): bool {
            if (! str_ends_with($request->url(), '/v1/generations')) {
                return true;
            }

            return $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
                && $request['metadata']['local_id'] === $generation->id
                && $request['prompt'] === 'a lighthouse on a cliff at dawn';
        });
    }

    public function test_blocked_prompt_marks_failed_free_and_shows_error(): void
    {
        $this->fakeProvider([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'content_policy_violation',
                'message' => 'The prompt was rejected.',
            ],
        ], 400);

        $user = User::factory()->create(['balance' => '10.000000000']);

        $this->actingAs($user)
            ->from(route('generations.create'))
            ->post(route('generations.store'), $this->params(['prompt' => '[blocked] violence']))
            ->assertSessionHasErrors('prompt');

        $generation = Generation::query()->sole();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertNull($generation->user_charge);
        $this->assertSame('10.000000000', (string) $user->refresh()->balance);
    }

    public function test_overloaded_is_retried_then_marked_failed_without_charge(): void
    {
        $this->fakeProvider([
            'error' => [
                'type' => 'api_error',
                'code' => 'overloaded',
                'message' => 'Pixelforge is temporarily overloaded.',
            ],
        ], 503);

        $user = User::factory()->create(['balance' => '10.000000000']);

        $this->actingAs($user)
            ->from(route('generations.create'))
            ->post(route('generations.store'), $this->params())
            ->assertSessionHasErrors();

        $generation = Generation::query()->sole();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('overloaded', $generation->error_code);
        $this->assertNull($generation->user_charge);
    }

    public function test_insufficient_balance_blocks_without_calling_provider(): void
    {
        $this->fakeProvider();
        $user = User::factory()->create(['balance' => '0.000000000']);

        $this->actingAs($user)
            ->from(route('generations.create'))
            ->post(route('generations.store'), $this->params())
            ->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::query()->count());
        Http::assertNotSent(
            fn (\Illuminate\Http\Client\Request $request): bool => str_ends_with($request->url(), '/v1/generations')
        );
    }
}
