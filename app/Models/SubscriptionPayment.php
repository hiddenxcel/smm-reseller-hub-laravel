<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS billing (tenant -> platform). transaction_ref is UNIQUE and
 * markSuccess() is a compare-and-swap, together giving replay protection on
 * gateway webhooks that retry.
 */
class SubscriptionPayment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'subscription_id',
        'gateway',
        'transaction_ref',
        'amount',
        'credit_applied',
        'currency',
        'months',
        'items',
        'status',
        'raw_response',
        'binance_order_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credit_applied' => 'decimal:2',
            'months' => 'integer',
            // What the cart held: one payment can buy both bots and a number,
            // and the webhook replays this list to apply them.
            'items' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Look up a payment by its gateway reference, across tenants — a webhook
     * has no session, and the reference is globally unique.
     */
    public static function findByRefAnyTenant(string $ref): ?self
    {
        return static::withoutTenantScope()->where('transaction_ref', $ref)->first();
    }

    /**
     * Compare-and-swap to 'success'. Returns true only for the caller that
     * actually flipped it, so a retried webhook cannot double-credit.
     */
    public function markSuccess(): bool
    {
        return static::withoutTenantScope()
            ->whereKey($this->getKey())
            ->where('status', '!=', 'success')
            ->update(['status' => 'success']) === 1;
    }
}
