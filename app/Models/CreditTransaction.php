<?php

namespace App\Models;

use App\Enums\CreditTransactionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in a user's credit ledger. The balance is the bcmath
 * sum of every row's `amount`, so a row is never updated or deleted once written.
 *
 * @property string $amount signed decimal string; positive adds credit, negative removes it
 * @property CreditTransactionReason $reason
 */
class CreditTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'generation_id',
        'reason',
        'amount',
        'description',
    ];

    /**
     * Money stays a plain string on purpose, so bcmath keeps it exact.
     */
    protected function casts(): array
    {
        return [
            'reason' => CreditTransactionReason::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
