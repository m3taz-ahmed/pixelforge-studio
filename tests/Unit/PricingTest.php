<?php

namespace Tests\Unit;

use App\Services\Pricing;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    public function test_computes_the_documented_worked_example_exactly(): void
    {
        // From https://pixelforge.promptedit.dev/docs "the most a generation can
        // cost": 115 text, 1530 image, 6450 output, 8000 reasoning tokens at
        // 5.00/10.00/40.00/12.50 dollars per 1M.
        $cost = Pricing::providerCost([
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ], [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);

        $this->assertSame('0.373875000', (string) $cost);
    }

    public function test_charges_exactly_provider_cost_plus_25_percent(): void
    {
        $charge = Pricing::userCharge(BigDecimal::of('0.373875000'));

        $this->assertSame('0.467343750', (string) $charge);
    }

    public function test_money_is_never_rounded_below_nine_decimals(): void
    {
        $cost = Pricing::providerCost([
            'input_text_tokens' => 1,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ], [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);

        $this->assertSame('0.000005000', (string) $cost);
    }

    public function test_display_formats_decimal_strings(): void
    {
        $this->assertSame('0.046125', Pricing::display('0.046125000'));
        $this->assertSame('10.00', Pricing::display('10.000000000'));
        $this->assertSame('0.50', Pricing::display('0.500000000'));
        $this->assertNull(Pricing::display(null));
    }
}
