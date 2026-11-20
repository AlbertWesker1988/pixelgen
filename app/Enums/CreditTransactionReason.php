<?php

namespace App\Enums;

/**
 * Why a credit ledger row exists.
 *
 *   purchase      -> the user bought credits (a fake, no-payment top-up). Positive.
 *   hold          -> reserved up front when a generation starts, at the upper-bound
 *                    estimate, so a user can never spend credit they do not have. Negative.
 *   settle_refund -> released once the generation settles: the unused part of the hold
 *                    (succeeded), or the whole hold (failed, never billed). Positive.
 */
enum CreditTransactionReason: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case SettleRefund = 'settle_refund';
}
