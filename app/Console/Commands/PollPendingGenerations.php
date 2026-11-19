<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fallback for when webhooks do not arrive: pull the current state of every
 * generation that is still in flight and apply it. Safe to run repeatedly,
 * because GenerationUpdater never moves a settled generation backwards.
 */
#[Signature('generations:poll')]
#[Description('Poll Pixelforge for generations that have not settled yet.')]
class PollPendingGenerations extends Command
{
    public function handle(PixelforgeClient $client, GenerationUpdater $updater): int
    {
        $pending = Generation::query()
            ->whereNotNull('pixelforge_id')
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->get();

        $this->info("Polling {$pending->count()} pending generation(s).");

        foreach ($pending as $generation) {
            try {
                $object = $client->getGeneration($generation->pixelforge_id);
                $updater->apply($generation, $object);
                $this->line("  {$generation->pixelforge_id}: {$generation->status->value}");
            } catch (PixelforgeException $e) {
                $this->warn("  {$generation->pixelforge_id}: {$e->errorCode}");
            }
        }

        return self::SUCCESS;
    }
}
