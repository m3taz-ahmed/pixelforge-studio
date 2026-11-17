<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    use HasUuids;

    public const TYPE_TOPUP = 'topup';
    public const TYPE_CHARGE = 'charge';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'generation_id',
        'type',
        'amount',
        'balance_after',
        'memo',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
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
