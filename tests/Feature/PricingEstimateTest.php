<?php

namespace Tests\Feature;

use App\Services\Pricing;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PricingEstimateTest extends TestCase
{
    private function fakePricing(): void
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
        ]);
    }

    public function test_estimate_uses_expected_values_inside_documented_ranges(): void
    {
        $this->fakePricing();

        $estimate = Pricing::estimate('a lighthouse on a cliff', '1536x1024', 'high', [
            'https://example.com/one.png',
            'https://example.com/two.png',
        ]);

        $this->assertSame(6, $estimate['tokens']['input_text_tokens']);
        $this->assertSame(1530, $estimate['tokens']['input_image_tokens']);
        $this->assertSame(6450, $estimate['tokens']['output_tokens']);
        $this->assertSame(4500, $estimate['tokens']['reasoning_tokens']);
        $this->assertTrue(BigDecimal::of($estimate['min'])->isLessThan($estimate['estimated']));
        $this->assertTrue(BigDecimal::of($estimate['estimated'])->isLessThan($estimate['max']));
    }
}
