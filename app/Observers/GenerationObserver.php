<?php

namespace App\Observers;

use App\Models\Generation;
use App\Services\Credits\CreditLedger;

/**
 * Releases a generation's credit hold the moment it settles.
 *
 * Centralising this on the model's "updated" event means every path that lands a
 * generation in a final state settles the ledger identically: a succeeded or
 * failed webhook, the polling fallback, and the create_failed job hook. The
 * ledger's settle() is idempotent, so firing on each status change is safe.
 */
class GenerationObserver
{
    public function __construct(private readonly CreditLedger $ledger) {}

    public function updated(Generation $generation): void
    {
        if ($generation->wasChanged('status') && $generation->isFinal()) {
            $this->ledger->settle($generation);
        }
    }
}
