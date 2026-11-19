<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\CreateGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CreateGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    private function generation(): Generation
    {
        return User::factory()->create()->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Creating,
            'prompt' => 'a cat',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_user_cost' => '0.1',
        ]);
    }

    /** Use a client that does not retry, so transient failures surface immediately. */
    private function noRetryClient(): PixelforgeClient
    {
        return new PixelforgeClient('https://pixelforge.test/v1', 'pf_test', maxAttempts: 1);
    }

    public function test_terminal_error_settles_as_create_failed(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'no'],
            ], 400),
        ]);

        $generation = $this->generation();
        (new CreateGenerationJob($generation))->handle($this->noRetryClient());

        $generation->refresh();
        $this->assertSame(GenerationStatus::CreateFailed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error['code']);
    }

    public function test_transient_error_is_rethrown_for_a_later_retry(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'busy'],
            ], 503),
        ]);

        $generation = $this->generation();

        try {
            (new CreateGenerationJob($generation))->handle($this->noRetryClient());
            $this->fail('Expected a PixelforgeException to bubble up.');
        } catch (PixelforgeException $e) {
            $this->assertFalse($e->isTerminal());
        }

        // Still creating, so the queue can retry it.
        $this->assertSame(GenerationStatus::Creating, $generation->refresh()->status);
    }

    public function test_failed_hook_settles_a_stuck_generation(): void
    {
        $generation = $this->generation();

        (new CreateGenerationJob($generation))->failed(new RuntimeException('gave up'));

        $this->assertSame(GenerationStatus::CreateFailed, $generation->refresh()->status);
    }
}
