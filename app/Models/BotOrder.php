<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotOrder extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'panel_id',
        'provider_order_id',
        'customer_phone',
        'customer_id',
        'service_id',
        'service_name',
        'link',
        'quantity',
        'remains',
        'amount',
        'refunded_amount',
        'refunded_at',
        'payment_status',
        'paid_from',
        'order_error',
        'charge',
        'status',
        'refill_status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'charge' => 'decimal:4',
            'quantity' => 'integer',
            'remains' => 'integer',
            'refunded_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }

    /** Whether the provider has ever been given this order. */
    public function reachedProvider(): bool
    {
        return $this->provider_order_id !== null;
    }

    /**
     * What a customer is told about this order.
     *
     * An order that failed before the provider ever got it is the reseller's
     * to resend, not the customer's to worry about: to them it is still
     * pending. Once the provider has it, the status is the provider's word.
     */
    public function customerStatus(): string
    {
        if (! $this->reachedProvider() && OrderStatus::fold($this->status) === OrderStatus::FAILED) {
            return 'Pending';
        }

        return $this->status ?: 'pending';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BotCustomer::class, 'customer_id');
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(TenantPanel::class, 'panel_id');
    }
}
