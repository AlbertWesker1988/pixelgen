<?php

namespace App\Services\Pixelforge;

use RuntimeException;

/**
 * A Pixelforge error surfaced to our code. It is thrown either for a terminal
 * error we must not retry (validation, content policy, 404, auth, idempotency),
 * or after the client has exhausted its retries on a transient error (429, 503).
 * Callers use isTerminal() to decide between giving up and retrying later.
 */
class PixelforgeException extends RuntimeException
{
    /** Error types that are transient: the request could succeed if retried later. */
    private const array TRANSIENT_TYPES = ['rate_limit_error', 'api_error'];

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $errorType,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Terminal errors will never succeed on retry (bad input, policy, auth).
     * A transient error reaching here means retries were already exhausted, but
     * the caller may still choose to try again later on a fresh job attempt.
     */
    public function isTerminal(): bool
    {
        return ! in_array($this->errorType, self::TRANSIENT_TYPES, true);
    }

    public function isContentPolicy(): bool
    {
        return $this->errorCode === 'content_policy_violation';
    }

    public function isValidation(): bool
    {
        return $this->errorCode === 'validation_failed';
    }
}
