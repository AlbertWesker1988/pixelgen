<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionReason;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): CreditLedger
    {
        return new CreditLedger;
    }

    private function generation(User $user, string $estimate): Generation
    {
        return $user->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Creating,
            'prompt' => 'a cat',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_user_cost' => $estimate,
        ]);
    }

    public function test_balance_starts_at_zero_and_a_purchase_adds_to_it(): void
    {
        $user = User::factory()->create();
        $ledger = $this->ledger();

        $this->assertSame('0', $ledger->balanceFor($user));

        $ledger->purchase($user, '5');

        $this->assertSame('5', $ledger->balanceFor($user));
    }

    public function test_a_succeeded_generation_charges_the_actual_cost_not_the_estimate(): void
    {
        $user = User::factory()->create();
        $ledger = $this->ledger();
        $ledger->purchase($user, '5');

        $generation = $this->generation($user, '0.46734375');
        $ledger->hold($user, $generation, '0.46734375');

        // Hold reserves the full upper-bound estimate up front.
        $this->assertSame('4.53265625', $ledger->balanceFor($user));

        // Settling the real cost (via the observer) releases the unused part.
        $generation->update(['status' => GenerationStatus::Succeeded, 'user_cost' => '0.2']);

        $this->assertSame('4.8', $ledger->balanceFor($user));
    }

    public function test_a_failed_generation_refunds_the_whole_hold(): void
    {
        $user = User::factory()->create();
        $ledger = $this->ledger();
        $ledger->purchase($user, '5');

        $generation = $this->generation($user, '0.46734375');
        $ledger->hold($user, $generation, '0.46734375');

        $generation->update(['status' => GenerationStatus::Failed]);

        $this->assertSame('5', $ledger->balanceFor($user));
    }

    public function test_a_create_failed_generation_refunds_the_whole_hold(): void
    {
        $user = User::factory()->create();
        $ledger = $this->ledger();
        $ledger->purchase($user, '5');

        $generation = $this->generation($user, '0.1');
        $ledger->hold($user, $generation, '0.1');

        $generation->update(['status' => GenerationStatus::CreateFailed]);

        $this->assertSame('5', $ledger->balanceFor($user));
    }

    public function test_settling_twice_never_refunds_twice(): void
    {
        $user = User::factory()->create();
        $ledger = $this->ledger();
        $ledger->purchase($user, '5');

        $generation = $this->generation($user, '0.46734375');
        $ledger->hold($user, $generation, '0.46734375');
        $generation->update(['status' => GenerationStatus::Succeeded, 'user_cost' => '0.2']);

        // A late poll re-applies the same final state: settle must be a no-op.
        $ledger->settle($generation->refresh());

        $this->assertSame('4.8', $ledger->balanceFor($user));
        $this->assertSame(1, $generation->creditTransactions()
            ->where('reason', CreditTransactionReason::SettleRefund)
            ->count());
    }

    public function test_balance_stays_exact_across_many_fractional_entries(): void
    {
        $user = User::factory()->create();
        $ledger = $this->ledger();

        $ledger->purchase($user, '0.00005');
        $ledger->purchase($user, '0.00004');
        $ledger->purchase($user, '0.00001');

        // 0.00005 + 0.00004 + 0.00001 = 0.0001, no float drift.
        $this->assertSame('0.0001', $ledger->balanceFor($user));
    }
}
