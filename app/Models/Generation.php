<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'user_id',
        'provider_id',
        'idempotency_key',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'request_payload',
        'status',
        'rates',
        'usage',
        'estimated_cost',
        'provider_cost',
        'user_charge',
        'image_url',
        'image_url_expires_at',
        'error_code',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'request_payload' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_cost' => 'decimal:9',
            'provider_cost' => 'decimal:9',
            'user_charge' => 'decimal:9',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Status ordering for the state machine. Terminal statuses share the top
     * rank so a late "processing" event can never rewind a finished row.
     */
    public static function statusRank(string $status): int
    {
        return match ($status) {
            self::STATUS_PENDING => 0,
            self::STATUS_QUEUED => 1,
            self::STATUS_PROCESSING => 2,
            self::STATUS_SUCCEEDED, self::STATUS_FAILED => 3,
            default => -1,
        };
    }

    public function estimatedCharge(): BigDecimal
    {
        return BigDecimal::of($this->estimated_cost);
    }
}
