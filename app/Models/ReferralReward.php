<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credit earned when a referred tenant makes their first payment. Keyed on
 * referrer_id/referred_id (both tenants), so it is not scoped to a single
 * tenant_id column.
 */
class ReferralReward extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'amount',
        'currency',
        'payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'referred_id');
    }
}
