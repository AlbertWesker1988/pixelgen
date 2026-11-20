<?php

namespace App\Services\Credits;

use App\Enums\CreditTransactionReason;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;

/**
 * The single home for every credit-money operation.
 *
 * The ledger is append-only: a balance is the exact bcmath sum of a user's rows,
 * never a mutable column, so it can always be reconstructed and audited. All
 * amounts are decimal STRINGS; we never touch float and never let the database
 * sum money, because a float with nine-plus decimals drifts.
 *
 * Spending uses hold/settle (authorise, then capture), the same shape as a card
 * hold: at generation time we reserve the upper-bound estimate so concurrent
 * requests can never overspend, then release the unused part once the real cost
 * is known. A failed generation is never billed, so its whole hold is released.
 */
class CreditLedger
{
    /** Generous bcmath precision so nothing is rounded mid-calculation. */
    private const int SCALE = 18;

    /** A user's current balance as an exact decimal string. */
    public function balanceFor(User $user): string
    {
        $total = '0';

        foreach ($user->creditTransactions()->get() as $transaction) {
            $total = bcadd($total, $transaction->amount, self::SCALE);
        }

        return $this->trim($total);
    }

    /** True when the balance covers the given cost (balance >= cost). */
    public function canAfford(User $user, string $cost): bool
    {
        return bccomp($this->balanceFor($user), $cost, self::SCALE) >= 0;
    }

    /** Record a fake, no-payment top-up. */
    public function purchase(User $user, string $amount): CreditTransaction
    {
        return $this->record($user, CreditTransactionReason::Purchase, $this->trim($amount), null, 'Credit purchase');
    }

    /**
     * Reserve the estimate up front. The amount is stored negative, so the
     * balance drops immediately and the credit cannot be spent twice.
     *
     * Call inside a transaction that locks the user row, after checking
     * canAfford(), so two concurrent generations cannot both pass the check.
     */
    public function hold(User $user, Generation $generation, string $estimate): CreditTransaction
    {
        $amount = bcmul($this->trim($estimate), '-1', self::SCALE);

        return $this->record($user, CreditTransactionReason::Hold, $this->trim($amount), $generation, 'Generation hold');
    }

    /**
     * Release a hold once the generation reaches a final state. Succeeded
     * generations keep the actual user_cost and refund the rest; failed and
     * create_failed generations are never billed, so the whole hold returns.
     *
     * Idempotent: a generation without a hold (created before balances existed)
     * is ignored, and a hold that was already settled is never refunded twice.
     */
    public function settle(Generation $generation): void
    {
        $hold = $generation->creditTransactions()
            ->where('reason', CreditTransactionReason::Hold)
            ->first();

        if ($hold === null) {
            return; // not a balance-tracked generation
        }

        $alreadySettled = $generation->creditTransactions()
            ->where('reason', CreditTransactionReason::SettleRefund)
            ->exists();

        if ($alreadySettled) {
            return;
        }

        $held = bcmul($hold->amount, '-1', self::SCALE); // the positive reserved amount
        $charge = $generation->status === GenerationStatus::Succeeded
            ? ($generation->user_cost ?? '0')
            : '0';
        $refund = bcsub($held, $charge, self::SCALE);

        if (bccomp($refund, '0', self::SCALE) <= 0) {
            return; // nothing to give back (actual met or matched the estimate)
        }

        $this->record($generation->user, CreditTransactionReason::SettleRefund, $this->trim($refund), $generation, 'Hold released');
    }

    private function record(
        User $user,
        CreditTransactionReason $reason,
        string $amount,
        ?Generation $generation,
        string $description,
    ): CreditTransaction {
        return $user->creditTransactions()->create([
            'generation_id' => $generation?->id,
            'reason' => $reason,
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    /**
     * Drop trailing zeros but keep the exact value: "5.00" -> "5", never rounds.
     */
    private function trim(string $amount): string
    {
        if (! str_contains($amount, '.')) {
            return $amount;
        }

        $amount = rtrim(rtrim($amount, '0'), '.');

        return $amount === '' || $amount === '-0' ? '0' : $amount;
    }
}
