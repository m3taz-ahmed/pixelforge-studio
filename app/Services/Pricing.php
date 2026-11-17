<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;

/**
 * Exact money math for Pixelforge billing.
 *
 * Provider cost = Σ (token_count × rate ÷ 1_000_000) over the four token
 * types, using the rates frozen on each generation — never the current rates.
 * All amounts are decimal strings with 9 decimal places; BigDecimal is used
 * for every operation so no float ever enters the calculation.
 *
 * The user charge is the provider cost plus a 25% markup.
 */
class Pricing
{
    public const MARKUP = '1.25';

    public const TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /**
     * Fallback token model, mirroring GET /v1/pricing `tokens`. Used only when
     * the pricing endpoint is unreachable; live data is preferred and cached.
     */
    private const FALLBACK_MODEL = [
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
    ];

    /**
     * Provider cost for a finished generation: Σ tokens × rate / 1M.
     *
     * @param  array<string, int|numeric-string>  $usage
     * @param  array<string, string>  $rates  Dollars per 1M tokens, per type.
     */
    public static function providerCost(array $usage, array $rates): BigDecimal
    {
        $cost = BigDecimal::of('0');

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = BigDecimal::of((string) ($usage[$type] ?? 0));
            $rate = BigDecimal::of((string) ($rates[$type] ?? '0'));
            $cost = $cost->plus($tokens->multipliedBy($rate));
        }

        return $cost->dividedBy('1000000', 9, RoundingMode::DOWN);
    }

    /**
     * What the user pays: provider cost + 25% markup.
     */
    public static function userCharge(BigDecimal $providerCost): BigDecimal
    {
        return $providerCost->multipliedBy(self::MARKUP)->toScale(9, RoundingMode::DOWN);
    }

    /**
     * Up-front price estimate for the generate form.
     *
     * Heuristic (this is what they ask about): only output tokens are known
     * exactly before submission — they are fixed per size+quality. Text tokens
     * are estimated at ceil(chars/4) with ±15% variance, reference images are a
     * fixed 765 input-image tokens each, and reasoning varies inside a
     * quality-bounded range. We charge the *expected* value (midpoint of the
     * reasoning range) and show the user the worst case alongside it, so the
     * number on screen is never a surprise in either direction.
     *
     * @param  array<int, string>  $referenceImages
     * @return array{estimated: string, max: string, min: string, tokens: array<string, int>}
     */
    public static function estimate(string $prompt, string $size, string $quality, array $referenceImages): array
    {
        $model = self::tokenModel();
        $rates = self::rates();

        $chars = mb_strlen($prompt);
        $textExpected = (int) ceil($chars / $model['characters_per_text_token']);
        $imageTokens = count($referenceImages) * $model['per_reference_image'];
        $output = (int) $model['output'][$quality][$size];
        $reasoning = $model['reasoning'][$quality];

        $usage = [
            'input_text_tokens' => $textExpected,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $output,
            'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
        ];

        $minUsage = [
            'input_text_tokens' => (int) floor($textExpected * (1 - $model['text_token_variance'])),
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning['min'],
        ];

        $maxUsage = [
            'input_text_tokens' => (int) ceil($textExpected * (1 + $model['text_token_variance'])),
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning['max'],
        ];

        return [
            'estimated' => (string) self::userCharge(self::providerCost($usage, $rates)),
            'min' => (string) self::userCharge(self::providerCost($minUsage, $rates)),
            'max' => (string) self::userCharge(self::providerCost($maxUsage, $rates)),
            'tokens' => $usage,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function rates(): array
    {
        return self::pricing()['rates'];
    }

    /**
     * @return array{rates: array<string, string>, tokens: array<string, mixed>}
     */
    public static function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', 300, function (): array {
            try {
                $pricing = app(Pixelforge\PixelforgeClient::class)->pricing();

                return [
                    'rates' => $pricing['rates'],
                    'tokens' => $pricing['tokens'],
                ];
            } catch (Pixelforge\PixelforgeException) {
                return [
                    'rates' => [
                        'input_text_tokens' => '5.00',
                        'input_image_tokens' => '10.00',
                        'output_tokens' => '40.00',
                        'reasoning_tokens' => '12.50',
                    ],
                    'tokens' => self::FALLBACK_MODEL,
                ];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function tokenModel(): array
    {
        $tokens = self::pricing()['tokens'] ?? null;

        return is_array($tokens) ? $tokens : self::FALLBACK_MODEL;
    }

    /**
     * Format a decimal string for display: up to 6 significant decimals,
     * trailing zeros trimmed, at least 2 decimals kept.
     */
    public static function display(?string $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $rounded = BigDecimal::of($amount === '' ? '0' : $amount)->toScale(6, RoundingMode::HALF_UP);
        $trimmed = rtrim(rtrim((string) $rounded, '0'), '.');

        if (! str_contains($trimmed, '.')) {
            $trimmed .= '.00';
        } elseif (strlen(substr($trimmed, strpos($trimmed, '.') + 1)) < 2) {
            $trimmed .= '0';
        }

        return $trimmed;
    }
}
