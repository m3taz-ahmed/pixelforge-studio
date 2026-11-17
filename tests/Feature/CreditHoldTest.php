<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationSync;
use App\Services\Pricing;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Authorize/capture for credits: submit reserves the worst-case estimate,
 * settle releases the hold and takes only the actual charge, any failure
 * releases the hold untouched. Balance can never go negative.
 */
class CreditHoldTest extends TestCase
{
    use RefreshDatabase;

    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    protected function setUp(): void
    {
        parent::setUp();

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
                'rates' => self::RATES,
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
                    'status' => 'queued',
                    'metadata' => $request['metadata'] ?? [],
                    'rates' => self::RATES,
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

    private function maxEstimate(): string
    {
        $estimate = Pricing::estimate('a lighthouse on a cliff at dawn', '1024x1024', 'medium', []);

        return $estimate['max'];
    }

    private function submitGeneration(User $user): Generation
    {
        $this->actingAs($user)->post(route('generations.store'), $this->params())
            ->assertRedirect(route('generations.index'));

        return Generation::query()->sole();
    }

    public function test_submit_reserves_worst_case_estimate(): void
    {
        $this->fakeProvider();
        $user = User::factory()->create(['balance' => '10.000000000']);
        $max = $this->maxEstimate();

        $generation = $this->submitGeneration($user);

        $this->assertSame($max, (string) $generation->held_amount);
        $this->assertSame('9.913687500', (string) $user->refresh()->balance);

        $hold = CreditTransaction::query()->where('type', CreditTransaction::TYPE_HOLD)->sole();
        $this->assertTrue(BigDecimal::of($hold->amount)->isEqualTo(BigDecimal::of($max)->negated()));
        $this->assertSame('9.913687500', $hold->balance_after);
        $this->assertSame($generation->id, $hold->generation_id);
    }

    public function test_settle_releases_hold_and_charges_actual_only(): void
    {
        $this->fakeProvider();
        $user = User::factory()->create(['balance' => '10.000000000']);
        $generation = $this->submitGeneration($user);
        $held = (string) $generation->held_amount;

        $usage = [
            'input_text_tokens' => 9,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1100,
        ];
        $expectedCharge = Pricing::userCharge(Pricing::providerCost($usage, self::RATES));

        app(GenerationSync::class)->apply([
            'id' => 'gen_FAKE1',
            'status' => 'succeeded',
            'usage' => $usage,
            'rates' => self::RATES,
            'image_url' => 'https://example.test/i.png',
            'completed_at' => '2026-11-17T12:00:00Z',
        ]);

        $generation = $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertNull($generation->held_amount);
        $this->assertSame((string) $expectedCharge, (string) $generation->user_charge);

        // Net: initial − actual charge. The hold is fully released first.
        $expected = BigDecimal::of('10')->minus($expectedCharge);
        $this->assertSame((string) $expected, (string) $user->refresh()->balance);

        $txns = CreditTransaction::query()->where('generation_id', $generation->id)->get()->keyBy('type');
        $this->assertCount(3, $txns);
        $this->assertTrue(BigDecimal::of($txns[CreditTransaction::TYPE_HOLD]->amount)->isEqualTo(BigDecimal::of($held)->negated()));
        $this->assertTrue(BigDecimal::of($txns[CreditTransaction::TYPE_RELEASE]->amount)->isEqualTo($held));
        $this->assertTrue(BigDecimal::of($txns[CreditTransaction::TYPE_CHARGE]->amount)->isEqualTo($expectedCharge->negated()));
        $this->assertSame((string) $expected, $txns[CreditTransaction::TYPE_CHARGE]->balance_after);
    }

    public function test_failed_webhook_releases_full_hold(): void
    {
        $this->fakeProvider();
        $user = User::factory()->create(['balance' => '10.000000000']);
        $generation = $this->submitGeneration($user);

        app(GenerationSync::class)->apply([
            'id' => 'gen_FAKE1',
            'status' => 'failed',
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
            'completed_at' => '2026-11-17T12:00:00Z',
        ]);

        $generation = $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertNull($generation->held_amount);
        $this->assertNull($generation->user_charge);
        $this->assertSame('10.000000000', (string) $user->refresh()->balance);

        $types = CreditTransaction::query()->where('generation_id', $generation->id)->pluck('type')->sort()->values()->all();
        $this->assertSame([CreditTransaction::TYPE_HOLD, CreditTransaction::TYPE_RELEASE], $types);
        $this->assertSame(0, CreditTransaction::query()->where('type', CreditTransaction::TYPE_CHARGE)->count());
    }

    public function test_blocked_submit_releases_hold_immediately(): void
    {
        $this->fakeProvider([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'content_policy_violation',
                'message' => 'The prompt was rejected.',
            ],
        ], 400);

        $user = User::factory()->create(['balance' => '10.000000000']);

        $this->actingAs($user)->post(route('generations.store'), $this->params())
            ->assertSessionHasErrors();

        $generation = Generation::query()->sole();
        $this->assertNull($generation->held_amount);
        $this->assertSame('10.000000000', (string) $user->refresh()->balance);
        $this->assertSame(1, CreditTransaction::query()->where('type', CreditTransaction::TYPE_RELEASE)->count());
    }

    public function test_hold_consumes_balance_so_second_submit_is_blocked(): void
    {
        $this->fakeProvider();
        // Balance covers exactly one worst-case estimate.
        $max = $this->maxEstimate();
        $user = User::factory()->create(['balance' => $max]);

        $this->submitGeneration($user);

        $this->actingAs($user)
            ->from(route('generations.create'))
            ->post(route('generations.store'), $this->params())
            ->assertSessionHasErrors('credits');

        $this->assertSame(1, Generation::query()->count());
        $this->assertSame('0.000000000', (string) $user->refresh()->balance);
    }

    public function test_local_fail_via_sync_releases_hold(): void
    {
        $this->fakeProvider();
        $user = User::factory()->create(['balance' => '10.000000000']);
        $generation = $this->submitGeneration($user);

        app(GenerationSync::class)->failLocally($generation, 'submit_timeout', 'could not submit');

        $this->assertSame('10.000000000', (string) $user->refresh()->balance);
        $this->assertNull($generation->refresh()->held_amount);
    }
}
