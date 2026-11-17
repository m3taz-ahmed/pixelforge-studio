<?php

use App\Http\Controllers\Admin\UsageReportController;
use App\Http\Controllers\CreditBalanceController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (\Illuminate\Http\Request $request) {
        $recent = \App\Models\Generation::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(4)
            ->get(['id', 'prompt', 'status', 'image_url', 'created_at']);

        $counts = \App\Models\Generation::query()
            ->where('user_id', $request->user()->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('Dashboard', [
            'balance' => \App\Services\Pricing::display((string) $request->user()->balance),
            'counts' => $counts,
            'recent' => $recent,
        ]);
    })->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::get('history', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('credits', [CreditBalanceController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditBalanceController::class, 'store'])->name('credits.store');

    Route::get('admin/usage-report', UsageReportController::class)
        ->middleware('admin')
        ->name('admin.usage-report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
