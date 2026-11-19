<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private function generation(string $status = 'queued', array $attributes = []): Generation
    {
        return User::factory()->create()->generations()->create(array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'status' => $status,
            'prompt' => 'a cat',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_user_cost' => '0.1',
            'pixelforge_id' => 'gen_'.Str::random(8),
        ], $attributes));
    }

    /**
     * @param array<string,mixed> $object
     * @return array<string,mixed>
     */
    private function event(string $status, array $object): array
    {
        return [
            'id' => 'evt_'.Str::random(12),
            'object' => 'event',
            'type' => "generation.{$status}",
            'created_at' => now()->toIso8601String(),
            'data' => ['object' => $object],
        ];
    }

    /**
     * @param array<string,mixed> $object
     * @return array<string,mixed>
     */
    private function succeededObject(Generation $g): array
    {
        return [
            'id' => $g->pixelforge_id,
            'object' => 'generation',
            'status' => 'succeeded',
            'metadata' => ['local_id' => (string) $g->id],
            'usage' => [
                'input_text_tokens' => 10,
                'input_image_tokens' => 0,
                'output_tokens' => 1000,
                'reasoning_tokens' => 0,
                'total_tokens' => 1010,
            ],
            'rates' => self::RATES,
            'image_url' => 'https://pixelforge.test/img.png',
            'image_url_expires_at' => now()->addDay()->toIso8601String(),
            'error' => null,
            'completed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string,mixed> $event
     */
    private function postEvent(array $event, ?string $secret = null): TestResponse
    {
        $body = json_encode($event);
        $timestamp = time();
        $secret ??= config('services.pixelforge.webhook_secret');
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call(
            method: 'POST',
            uri: '/webhooks/pixelforge',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $body,
        );
    }

    public function test_succeeded_webhook_records_exact_actual_cost(): void
    {
        $g = $this->generation();

        $this->postEvent($this->event('succeeded', $this->succeededObject($g)))
            ->assertNoContent();

        $g->refresh();
        $this->assertSame('succeeded', $g->status->value);
        // provider: 10/1e6*5 + 1000/1e6*40 = 0.04005 ; user *1.25 = 0.0500625
        $this->assertSame('0.04005', $g->provider_cost);
        $this->assertSame('0.0500625', $g->user_cost);
        $this->assertSame('https://pixelforge.test/img.png', $g->image_url);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_duplicate_delivery_is_processed_only_once(): void
    {
        $g = $this->generation();
        $event = $this->event('succeeded', $this->succeededObject($g));

        $this->postEvent($event)->assertNoContent();
        $this->postEvent($event)->assertNoContent(); // same event id again

        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $g = $this->generation();

        $this->postEvent($this->event('succeeded', $this->succeededObject($g)), secret: 'whsec_wrong')
            ->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame('queued', $g->refresh()->status->value);
    }

    public function test_out_of_order_event_never_reverts_a_final_generation(): void
    {
        $g = $this->generation('succeeded', ['user_cost' => '0.0500625', 'provider_cost' => '0.04005']);

        $processing = $this->event('processing', [
            'id' => $g->pixelforge_id,
            'status' => 'processing',
            'metadata' => ['local_id' => (string) $g->id],
        ]);

        $this->postEvent($processing)->assertNoContent();

        $g->refresh();
        $this->assertSame('succeeded', $g->status->value);
        $this->assertSame('0.0500625', $g->user_cost);
    }

    public function test_failed_generation_is_free(): void
    {
        $g = $this->generation();

        $failed = $this->event('failed', [
            'id' => $g->pixelforge_id,
            'status' => 'failed',
            'metadata' => ['local_id' => (string) $g->id],
            'error' => ['code' => 'generation_failed', 'message' => 'model could not produce an image'],
            'completed_at' => now()->toIso8601String(),
        ]);

        $this->postEvent($failed)->assertNoContent();

        $g->refresh();
        $this->assertSame('failed', $g->status->value);
        $this->assertNull($g->user_cost);
        $this->assertNull($g->provider_cost);
    }
}
