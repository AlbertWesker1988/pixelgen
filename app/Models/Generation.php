<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/**
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
**/
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
     * Statuses that will never change again.
     */
    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }
}
