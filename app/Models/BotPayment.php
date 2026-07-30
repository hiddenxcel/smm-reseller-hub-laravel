<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment from an end customer to a reseller (wallet top-up or direct order
 * payment) — distinct from SubscriptionPayment, which is the reseller paying
 * the platform. Do not conflate the two money flows.
 */
class BotPayment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'type',
        'customer_id',
        'order_id',
        'gateway',
        'transaction_ref',
        'amount',
        'status',
        'binance_order_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BotCustomer::class, 'customer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BotOrder::class, 'order_id');
    }

    /** Webhook lookup: no session, reference is unique per tenant+ref. */
    public static function findByRefAnyTenant(string $ref): ?self
    {
        return static::withoutTenantScope()->where('transaction_ref', $ref)->first();
    }

    /**
     * Compare-and-swap to 'success'. Only the caller that flips it gets true,
     * so a retried gateway webhook cannot credit the wallet twice.
     */
    public function markSuccess(): bool
    {
        return static::withoutTenantScope()
            ->whereKey($this->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'success']) === 1;
    }
}
