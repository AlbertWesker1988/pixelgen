<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\CreateGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * The generate form. We hand the page Pixelforge's pricing (rates + token
     * model) and our markup so it can preview the cost as the user types.
     */
    public function create(PixelforgeClient $client, CreditLedger $ledger): Response
    {
        return Inertia::render('Generations/Create', [
            'pricing' => $this->pricing($client),
            'markup' => (string) config('services.pixelforge.markup'),
            'balance' => $ledger->balanceFor(auth()->user()),
        ]);
    }

    /**
     * Record the generation locally, then hand the actual API call to a queued
     * job. The estimate stored here is computed server-side, so it is the
     * authoritative "what we showed the user" number.
     *
     * The affordability check and the credit hold run in one locked transaction:
     * we lock the user row, re-read the balance, and only then create the
     * generation and reserve the estimate. Two concurrent generations therefore
     * serialise, so a user can never overspend by firing requests in parallel.
     */
    public function store(StoreGenerationRequest $request, PixelforgeClient $client, CostCalculator $calc, CreditLedger $ledger): RedirectResponse
    {
        $data = $request->validated();
        $rates = $this->pricing($client)['rates'];
        $referenceImages = $data['reference_images'] ?? [];

        $estimate = $calc->estimate($rates, $data['prompt'], $data['size'], $data['quality'], $referenceImages);

        $generation = DB::transaction(function () use ($request, $data, $rates, $referenceImages, $estimate, $ledger): Generation {
            $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();

            if (! $ledger->canAfford($user, $estimate['user'])) {
                throw ValidationException::withMessages([
                    'credits' => "Not enough credits. This generation needs \${$estimate['user']} and your balance is \${$ledger->balanceFor($user)}. Buy more credits and try again.",
                ]);
            }

            $generation = $user->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Creating,
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $referenceImages,
                'rates' => $rates,
                'estimated_user_cost' => $estimate['user'],
            ]);

            $ledger->hold($user, $generation, $estimate['user']);

            return $generation;
        });

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
