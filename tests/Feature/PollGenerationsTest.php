<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PollGenerationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_polling_settles_a_pending_generation(): void
    {
        $generation = User::factory()->create()->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'queued',
            'prompt' => 'a dog',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_user_cost' => '0.1',
            'pixelforge_id' => 'gen_poll1',
        ]);

        Http::fake([
            '*/generations/gen_poll1' => Http::response([
                'id' => 'gen_poll1',
                'status' => 'succeeded',
                'metadata' => ['local_id' => (string) $generation->id],
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1000,
                    'reasoning_tokens' => 0,
                    'total_tokens' => 1010,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'image_url' => 'https://pixelforge.test/dog.png',
                'image_url_expires_at' => now()->addDay()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->artisan('generations:poll')->assertSuccessful();

        $generation->refresh();
        $this->assertSame('succeeded', $generation->status->value);
        $this->assertSame('0.0500625', $generation->user_cost);
        $this->assertSame('https://pixelforge.test/dog.png', $generation->image_url);
    }

    public function test_polling_ignores_generations_without_a_provider_id(): void
    {
        $generation = User::factory()->create()->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'creating',
            'prompt' => 'a bird',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'estimated_user_cost' => '0.01',
            'pixelforge_id' => null,
        ]);

        Http::fake();

        $this->artisan('generations:poll')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('creating', $generation->refresh()->status->value);
    }
}
