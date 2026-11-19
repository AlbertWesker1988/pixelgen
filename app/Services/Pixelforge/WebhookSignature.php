<?php

namespace App\Services\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header.
 *
 * Header format:  t=<unix timestamp>,v1=<hex signature>
 * Signature:      hex HMAC-SHA256 of "<timestamp>.<raw body>", keyed with the
 *                 API key's signing secret (whsec_...).
 *
 * We verify against the RAW body before any JSON decoding, compare in constant
 * time, and reject stale timestamps to stop replays.
 */
class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function verify(string $rawBody, ?string $header): bool
    {
        if ($header === null || $header === '') {
            return false;
        }

        [$timestamp, $signature] = $this->parse($header);

        if ($timestamp === null || $signature === null) {
            return false;
        }

        if (abs(time() - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array{0:?int,1:?string} [timestamp, signature]
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

            if ($key === 't' && is_numeric($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signature = $value;
            }
        }

        return [$timestamp, $signature];
    }
}
