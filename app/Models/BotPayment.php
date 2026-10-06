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
        'gateway_reference',
        'amount',
        'status',
        'binance_order_id',
        'pending_order',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'pending_order' => 'array',
            'last_checked_at' => 'datetime',
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

    /**
     * Webhook lookup: no session, reference is unique per tenant+ref.
     *
     * By our own reference first, then by the one the gateway issued. Gateways
     * disagree about which of the two they send back — some echo ours, others
     * (Snippe's "SN…", M-Pesa's CheckoutRequestID) send only their own — and a
     * payment that cannot be found when it clears is a customer who paid for
     * nothing.
     */
    public static function findByRefAnyTenant(string $ref): ?self
    {
        return static::withoutTenantScope()->where('transaction_ref', $ref)->first()
            ?? static::withoutTenantScope()->where('gateway_reference', $ref)->first();
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
