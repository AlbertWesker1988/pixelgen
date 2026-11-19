<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationUpdater;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies one verified, de-duplicated webhook event to its generation.
 *
 * The actual state change lives in GenerationUpdater, shared with the polling
 * fallback. Here we only resolve which local generation the event belongs to.
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<string,mixed> $event the full event payload
     */
    public function __construct(private readonly array $event) {}

    public function handle(GenerationUpdater $updater): void
    {
        $object = $this->event['data']['object'] ?? null;

        if (! is_array($object)) {
            return; // e.g. a ping, nothing to apply
        }

        $generation = $this->resolveGeneration($object);

        if ($generation !== null) {
            $updater->apply($generation, $object);
        }
    }

    /**
     * Prefer our own local id carried in metadata (works even before create
     * returned); fall back to the Pixelforge id.
     *
     * @param array<string,mixed> $object
     */
    private function resolveGeneration(array $object): ?Generation
    {
        $localId = $object['metadata']['local_id'] ?? null;

        if ($localId !== null) {
            return Generation::query()->find($localId);
        }

        return isset($object['id'])
            ? Generation::query()->where('pixelforge_id', $object['id'])->first()
            : null;
    }
}
