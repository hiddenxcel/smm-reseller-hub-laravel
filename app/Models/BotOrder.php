<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
        'amount',
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
        ];
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
