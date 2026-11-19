<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin, defensive wrapper over the Pixelforge REST API.
 *
 * Pixelforge misbehaves on purpose: rate limits, concurrency limits, random
 * 503s. This client retries the transient failures (429, 503, connection
 * errors) with backoff that respects Retry-After, and throws PixelforgeException
 * for terminal errors (validation, content policy, 404) which must NOT be retried.
 */
class PixelforgeClient
{
    private const array RETRYABLE_STATUSES = [429, 503];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $maxAttempts = 4,
        private readonly int $maxBackoffSeconds = 15,
    ) {}

    /**
     * GET /v1/pricing — current rates. No auth required, but sending the key is harmless.
     *
     * @return array<string,mixed>
     */
    public function getPricing(): array
    {
        return $this->send('get', 'pricing');
    }

    /**
     * POST /v1/generations — create a generation.
     *
     * The Idempotency-Key makes a retry after a 503 return the SAME generation
     * instead of creating a duplicate, so retrying is always safe.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed> the generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send('post', 'generations', $params, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /**
     * GET /v1/generations/{id} — fetch current state (used as the webhook fallback).
     *
     * @return array<string,mixed>
     */
    public function getGeneration(string $id): array
    {
        return $this->send('get', "generations/{$id}");
    }

    /**
     * GET /v1/usage — what Pixelforge billed this key, per UTC day. Source of
     * truth for the admin report's provider costs.
     *
     * @return array<string,mixed>
     */
    public function getUsage(string $startDate, string $endDate): array
    {
        return $this->send('get', 'usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    /**
     * Core request with retry. Retries 429/503/connection errors with backoff
     * (honouring Retry-After); throws PixelforgeException on terminal 4xx.
     *
     * @param array<string,mixed> $data   query for GET, json body for POST
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $data = [], array $headers = []): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->request($headers)->{$method}($path, $data);
            } catch (ConnectionException $e) {
                // Network blip: retry if we still can, otherwise surface it.
                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }
                $this->backoff($attempt, null);
                continue;
            }

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            if (in_array($response->status(), self::RETRYABLE_STATUSES, true) && $attempt < $this->maxAttempts) {
                $this->backoff($attempt, $this->retryAfter($response));
                continue;
            }

            // Terminal error, or retries exhausted: throw with the parsed code.
            throw $this->toException($response);
        }
    }

    private function request(array $headers): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout(20);
    }

    /**
     * Sleep before the next attempt. Prefer the server's Retry-After, otherwise
     * exponential backoff (1s, 2s, 4s, ...), capped.
     */
    private function backoff(int $attempt, ?int $retryAfter): void
    {
        $seconds = $retryAfter ?? (2 ** ($attempt - 1));
        sleep(min($seconds, $this->maxBackoffSeconds));
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? (int) $value : null;
    }

    private function toException(Response $response): PixelforgeException
    {
        $error = $response->json('error') ?? [];

        return new PixelforgeException(
            status: $response->status(),
            errorCode: $error['code'] ?? 'unknown',
            errorType: $error['type'] ?? 'unknown',
            message: $error['message'] ?? "Pixelforge request failed with status {$response->status()}",
        );
    }
}
