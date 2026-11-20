<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Observers\GenerationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string|null $estimated_user_cost
 * @property string|null $user_cost
 * @property string|null $provider_cost
 **/
#[ObservedBy(GenerationObserver::class)]
class Generation extends Model
{
    protected $fillable = [
        'user_id',
        'pixelforge_id',
        'idempotency_key',
        'status',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'rates',
        'usage',
        'estimated_user_cost',
        'provider_cost',
        'user_cost',
        'image_url',
        'image_url_expires_at',
        'error',
        'completed_at',
    ];

    /**
     * JSON columns become arrays; timestamps become Carbon instances.
     * Money stays a plain string on purpose, so bcmath keeps it exact.
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'error' => 'array',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Statuses that will never change again.
     */
    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }
}
