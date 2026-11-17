<?php

namespace App\Http\Controllers;

use App\Http\Requests\TopupCreditsRequest;
use App\Models\CreditTransaction;
use App\Services\Pricing;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CreditBalanceController extends Controller
{
    public function index(Request $request): Response
    {
        $transactions = CreditTransaction::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (CreditTransaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => Pricing::display($transaction->amount),
                'balance_after' => Pricing::display($transaction->balance_after),
                'memo' => $transaction->memo,
                'created_at' => $transaction->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Credits', [
            'balance' => Pricing::display((string) $request->user()->balance),
            'transactions' => $transactions,
        ]);
    }

    /**
     * Fake top-up: credits the balance inside a row lock so the ledger's
     * balance_after always agrees with the users.balance it mirrors.
     */
    public function store(TopupCreditsRequest $request): RedirectResponse
    {
        $amount = BigDecimal::of((string) $request->validated('amount'))->toScale(9);

        DB::transaction(function () use ($request, $amount): void {
            $user = \App\Models\User::query()
                ->whereKey($request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceAfter = $user->creditBalance()->plus($amount);
            $user->balance = (string) $balanceAfter;
            $user->save();

            CreditTransaction::query()->create([
                'user_id' => $user->id,
                'type' => CreditTransaction::TYPE_TOPUP,
                'amount' => (string) $amount,
                'balance_after' => (string) $balanceAfter,
                'memo' => 'Credit purchase (demo)',
            ]);
        });

        return to_route('credits.index');
    }
}
