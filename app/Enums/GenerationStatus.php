<?php

namespace App\Enums;

/**
 * The lifecycle of a generation.
 *
 *   creating  -> our local row exists, the create call has not returned yet
 *   queued    -> accepted by Pixelforge, waiting to process
 *   processing-> Pixelforge is working on it
 *   succeeded -> finished, billed
 *   failed    -> failed while processing, never billed
 *   create_failed -> rejected at submission (content policy, validation), never billed
 */
enum GenerationStatus: string
{
    case Creating = 'creating';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case CreateFailed = 'create_failed';

    /**
     * Terminal states that must never change again.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::CreateFailed => true,
            default => false,
        };
    }

    /**
     * States we still poll Pixelforge about.
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::Queued, self::Processing => true,
            default => false,
        };
    }
}
