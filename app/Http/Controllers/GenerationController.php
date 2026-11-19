<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\CreateGenerationJob;
use App\Models\Generation;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * The generate form. We hand the page Pixelforge's pricing (rates + token
     * model) and our markup so it can preview the cost as the user types.
     */
    public function create(PixelforgeClient $client): Response
    {
        return Inertia::render('Generations/Create', [
            'pricing' => $this->pricing($client),
            'markup' => (string) config('services.pixelforge.markup'),
        ]);
    }

    /**
     * Record the generation locally, then hand the actual API call to a queued
     * job. The estimate stored here is computed server-side, so it is the
     * authoritative "what we showed the user" number.
     */
    public function store(StoreGenerationRequest $request, PixelforgeClient $client, CostCalculator $calc): RedirectResponse
    {
        $data = $request->validated();
        $rates = $this->pricing($client)['rates'];
        $referenceImages = $data['reference_images'] ?? [];

        $estimate = $calc->estimate($rates, $data['prompt'], $data['size'], $data['quality'], $referenceImages);

        $generation = $request->user()->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Creating,
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $referenceImages,
            'rates' => $rates,
            'estimated_user_cost' => $estimate['user'],
        ]);

        CreateGenerationJob::dispatch($generation);

        return redirect()->route('generations.index')
            ->with('status', 'Generation submitted.');
    }

    /**
     * History for the signed-in user, newest first.
     */
    public function index(): Response
    {
        $generations = Generation::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(fn (Generation $g): array => [
                'id' => $g->id,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'status' => $g->status->value,
                'estimated_user_cost' => $g->estimated_user_cost,
                'user_cost' => $g->user_cost,
                'image_url' => $g->image_url,
                'error' => $g->error,
                'created_at' => $g->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
        ]);
    }

    /**
     * Pixelforge pricing, cached briefly so we do not call it on every page load.
     *
     * @return array<string,mixed>
     */
    private function pricing(PixelforgeClient $client): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinute(), fn (): array => $client->getPricing());
    }
}
