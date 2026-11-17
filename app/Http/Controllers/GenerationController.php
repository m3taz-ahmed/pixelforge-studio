<?php

namespace App\Http\Controllers;

use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Generate', [
            'balance' => Pricing::display((string) $request->user()->balance),
            'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
            'qualities' => ['low', 'medium', 'high'],
        ]);
    }

    public function estimate(EstimateGenerationRequest $request): JsonResponse
    {
        $params = $request->params();

        $estimate = Pricing::estimate($params['prompt'], $params['size'], $params['quality'], $params['reference_images']);

        return response()->json([
            'estimated' => Pricing::display($estimate['estimated']),
            'min' => Pricing::display($estimate['min']),
            'max' => Pricing::display($estimate['max']),
            'tokens' => $estimate['tokens'],
        ]);
    }

    public function store(StoreGenerationRequest $request, GenerationService $service): RedirectResponse
    {
        $service->submit($request->user(), $request->params());

        return to_route('generations.index');
    }

    public function index(Request $request): Response
    {
        $generations = Generation::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Generation $generation): array => [
                'id' => $generation->id,
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'status' => $generation->status,
                'estimated_cost' => Pricing::display($generation->estimated_cost),
                'user_charge' => Pricing::display($generation->user_charge),
                'image_url' => $generation->image_url,
                'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
                'error_message' => $generation->error_message,
                'created_at' => $generation->created_at->toIso8601String(),
            ]);

        return Inertia::render('History', [
            'generations' => $generations,
            'balance' => Pricing::display((string) $request->user()->balance),
        ]);
    }
}
