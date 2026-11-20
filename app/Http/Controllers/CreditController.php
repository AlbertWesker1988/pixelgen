<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditRequest;
use App\Models\CreditTransaction;
use App\Services\Credits\CreditLedger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /**
     * Balance plus the ledger history, newest first.
     */
    public function index(CreditLedger $ledger): Response
    {
        $transactions = auth()->user()->creditTransactions()
            ->latest()
            ->get()
            ->map(fn (CreditTransaction $t): array => [
                'id' => $t->id,
                'reason' => $t->reason->value,
                'amount' => $t->amount,
                'description' => $t->description,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Credits/Index', [
            'balance' => $ledger->balanceFor(auth()->user()),
            'packs' => PurchaseCreditRequest::PACKS,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Add credits. A fake purchase: no real payment, it just appends a positive
     * row to the ledger.
     */
    public function store(PurchaseCreditRequest $request, CreditLedger $ledger): RedirectResponse
    {
        $amount = $request->validated()['amount'];

        $ledger->purchase($request->user(), $amount);

        return redirect()->route('credits.index')
            ->with('status', "Added \${$amount} in credits.");
    }
}
