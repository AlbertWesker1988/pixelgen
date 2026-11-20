<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionReason;
use App\Enums\GenerationStatus;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GenerateWithCreditsTest extends TestCase
{
    use RefreshDatabase;

    /** Published rates, so the server-side estimate is deterministic. */
    private const array PRICING = [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // never actually call Pixelforge from these tests
        Http::fake(['*/pricing' => Http::response(self::PRICING)]);
    }

    private function generate(User $user): TestResponse
    {
        return $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'a cat',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ]);
    }

    public function test_generating_with_no_credits_is_blocked(): void
    {
        $user = User::factory()->create();

        $this->generate($user)->assertSessionHasErrors('credits');

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_generating_with_credits_holds_the_estimate(): void
    {
        $user = User::factory()->create();
        $ledger = app(CreditLedger::class);
        $ledger->purchase($user, '5');

        $this->generate($user)->assertRedirect(route('generations.index'));

        $generation = $user->generations()->sole();
        $this->assertSame(GenerationStatus::Creating, $generation->status);

        // Balance dropped by exactly the upper-bound estimate we stored.
        $expected = bcsub('5', $generation->estimated_user_cost, 18);
        $this->assertSame(0, bccomp($expected, $ledger->balanceFor($user), 18));

        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'reason' => CreditTransactionReason::Hold->value,
        ]);
    }

    public function test_buying_credits_adds_to_the_balance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('credits.store'), ['amount' => '20'])
            ->assertRedirect(route('credits.index'));

        $this->assertSame('20', app(CreditLedger::class)->balanceFor($user));
    }

    public function test_buying_an_unlisted_pack_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('credits.store'), ['amount' => '7'])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('credit_transactions', 0);
    }
}
