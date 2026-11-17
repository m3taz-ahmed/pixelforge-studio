<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pricing;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report: what users were charged vs what Pixelforge charged us.
 * Provider truth is GET /v1/usage (billed per UTC day a generation completed);
 * our local provider_cost sums must reconcile with it — the report shows the
 * delta explicitly rather than hiding discrepancies.
 */
class UsageReportController extends Controller
{
    private const MAX_RANGE_DAYS = 92;

    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        $end = $request->date('end') ?? now();
        $start = $request->date('start') ?? $end->copy()->subDays(6);

        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS || $start->greaterThan($end)) {
            $start = $end->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        $providerReport = $this->providerUsage($client, $start->toDateString(), $end->toDateString());

        $local = Generation::query()
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['user_id', 'provider_cost', 'user_charge', 'completed_at']);

        $charges = CreditTransaction::query()
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->with('user:id,name,email')
            ->get();

        $days = collect();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();

            $localCost = $local->filter(
                fn (Generation $generation): bool => $generation->completed_at->toDateString() === $date
            )->reduce(
                fn (BigDecimal $carry, Generation $generation): BigDecimal => $carry->plus((string) $generation->provider_cost),
                BigDecimal::of('0')
            );

            $localSpend = $local->filter(
                fn (Generation $generation): bool => $generation->completed_at->toDateString() === $date
            )->reduce(
                fn (BigDecimal $carry, Generation $generation): BigDecimal => $carry->plus((string) $generation->user_charge),
                BigDecimal::of('0')
            );

            $reported = $providerReport[$date] ?? null;
            $reportedCost = $reported !== null ? BigDecimal::of($reported['cost_usd']) : null;

            $days->push([
                'date' => $date,
                'generations' => $reported['generations'] ?? $local->filter(
                    fn (Generation $generation): bool => $generation->completed_at->toDateString() === $date
                )->count(),
                'provider_cost_local' => Pricing::display((string) $localCost),
                'provider_cost_reported' => $reportedCost !== null ? Pricing::display((string) $reportedCost) : null,
                'reconcile_delta' => $reportedCost !== null ? Pricing::display((string) $localCost->minus($reportedCost)) : null,
                'credit_spend' => Pricing::display((string) $localSpend),
                'profit' => Pricing::display((string) $localSpend->minus($localCost)),
            ]);

            $cursor->addDay();
        }

        $perUser = $charges->groupBy('user_id')->map(function ($rows, $userId) use ($local): array {
            $spend = $rows->reduce(
                fn (BigDecimal $carry, CreditTransaction $transaction): BigDecimal => $carry->minus((string) $transaction->amount),
                BigDecimal::of('0')
            );

            $cost = $local->where('user_id', (int) $userId)->reduce(
                fn (BigDecimal $carry, Generation $generation): BigDecimal => $carry->plus((string) $generation->provider_cost),
                BigDecimal::of('0')
            );

            return [
                'user' => $rows->first()->user->email ?? (string) $userId,
                'credit_spend' => Pricing::display((string) $spend),
                'provider_cost' => Pricing::display((string) $cost),
                'profit' => Pricing::display((string) $spend->minus($cost)),
            ];
        })->values();

        return Inertia::render('admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'perUser' => $perUser,
            'providerUsageUnavailable' => $providerReport === [],
        ]);
    }

    /**
     * @return array<string, array{generations: int, cost_usd: string}>
     */
    private function providerUsage(PixelforgeClient $client, string $start, string $end): array
    {
        return Cache::remember("pixelforge.usage.{$start}.{$end}", 60, function () use ($client, $start, $end): array {
            try {
                return collect($client->usage($start, $end)['data'] ?? [])
                    ->keyBy('date')
                    ->map(fn (array $row): array => [
                        'generations' => (int) $row['generations'],
                        'cost_usd' => (string) $row['cost_usd'],
                    ])
                    ->all();
            } catch (PixelforgeException) {
                return [];
            }
        });
    }
}
