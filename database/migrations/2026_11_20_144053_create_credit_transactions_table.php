<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Set for holds and settlement refunds; null for a plain credit purchase.
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();

            // purchase | hold | settle_refund (App\Enums\CreditTransactionReason).
            $table->string('reason');

            // Signed money as an exact decimal STRING. Positive adds credit
            // (purchase, refund), negative removes it (hold). Never float, never
            // summed by the database: the balance is folded in PHP with bcmath.
            $table->string('amount');

            // Human-readable note for the ledger view, e.g. "Credit purchase".
            $table->string('description')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
