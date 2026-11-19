<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use App\Models\Generation;

/**
 * Applies a Pixelforge generation object to our local Generation.
 *
 * Shared by the webhook handler and the polling fallback so both paths settle
 * a generation the exact same way: never move back from a final state, and
 * compute the actual cost from the object's own rates + usage.
 */
class GenerationUpdater
{
    public function __construct(private readonly CostCalculator $calc) {}

    /**
     * @param array<string,mixed> $object a Pixelforge generation object
     */
    public function apply(Generation $generation, array $object): void
    {
        if ($generation->isFinal()) {
            return; // already settled: never go backwards
        }

        if ($generation->pixelforge_id === null && isset($object['id'])) {
            $generation->pixelforge_id = $object['id'];
        }

        match ($object['status'] ?? null) {
            'processing' => $generation->status = GenerationStatus::Processing,
            'succeeded' => $this->applySuccess($generation, $object),
            'failed' => $this->applyFailure($generation, $object),
            default => null,
        };

        $generation->save();
    }

    /**
     * @param array<string,mixed> $object
     */
    private function applySuccess(Generation $generation, array $object): void
    {
        $cost = $this->calc->actual($object['rates'] ?? [], $object['usage'] ?? []);

        $generation->forceFill([
            'status' => GenerationStatus::Succeeded,
            'usage' => $object['usage'] ?? null,
            'rates' => $object['rates'] ?? $generation->rates,
            'provider_cost' => $cost['provider'],
            'user_cost' => $cost['user'],
            'image_url' => $object['image_url'] ?? null,
            'image_url_expires_at' => $object['image_url_expires_at'] ?? null,
            'completed_at' => $object['completed_at'] ?? now(),
        ]);
    }

    /**
     * @param array<string,mixed> $object
     */
    private function applyFailure(Generation $generation, array $object): void
    {
        // Failed generations are never billed, so cost stays null.
        $generation->forceFill([
            'status' => GenerationStatus::Failed,
            'error' => $object['error'] ?? null,
            'completed_at' => $object['completed_at'] ?? now(),
        ]);
    }
}
