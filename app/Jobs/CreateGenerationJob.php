<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a locally-created generation to Pixelforge.
 *
 * Runs on the queue so the client's retries/backoff (429, 503) never block the
 * web request. The webhook can arrive before this even returns, so we pass our
 * local id as metadata and the webhook handler matches on it.
 *
 * Terminal errors (content policy, validation) settle the generation as failed
 * immediately. A transient error that survived the client's own retries is
 * re-thrown so the queue retries the whole job later; once those attempts are
 * exhausted, failed() settles it.
 */
class CreateGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds to wait between job retries. */
    public array $backoff = [10, 30, 60];

    public function __construct(private readonly Generation $generation) {}

    public function handle(PixelforgeClient $client): void
    {
        // Already handled (e.g. a webhook raced ahead and advanced it): do nothing.
        if ($this->generation->status !== GenerationStatus::Creating) {
            return;
        }

        try {
            $response = $client->createGeneration(
                params: [
                    'prompt' => $this->generation->prompt,
                    'size' => $this->generation->size,
                    'quality' => $this->generation->quality,
                    'reference_images' => $this->generation->reference_images ?? [],
                    'metadata' => ['local_id' => (string) $this->generation->id],
                ],
                idempotencyKey: $this->generation->idempotency_key,
            );
        } catch (PixelforgeException $e) {
            // Transient failure that outlived the client's retries: let the queue
            // retry the whole job later instead of burying the generation.
            if (! $e->isTerminal()) {
                throw $e;
            }

            // Terminal rejection (content policy, validation): nothing was created or billed.
            $this->markFailed($e->errorCode, $e->getMessage());

            return;
        }

        // A webhook may have advanced us past 'creating' while we were waiting.
        // Only fill create-time fields, and never pull the status backwards.
        $this->generation->refresh();

        $this->generation->forceFill([
            'pixelforge_id' => $response['id'],
            'rates' => $response['rates'] ?? null,
        ]);

        if ($this->generation->status === GenerationStatus::Creating) {
            $this->generation->status = GenerationStatus::tryFrom($response['status'] ?? '') ?? GenerationStatus::Queued;
        }

        $this->generation->save();
    }

    /**
     * Called by the queue when every attempt has been exhausted (or on a
     * non-retryable throw). Settle the generation so it does not hang in
     * 'creating' forever.
     */
    public function failed(?Throwable $e): void
    {
        if ($this->generation->fresh()?->status === GenerationStatus::Creating) {
            $this->markFailed('create_failed', $e?->getMessage() ?? 'Could not create the generation.');
        }
    }

    private function markFailed(string $code, string $message): void
    {
        $this->generation->update([
            'status' => GenerationStatus::CreateFailed,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
