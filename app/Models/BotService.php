<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service in a reseller's own catalogue, at the reseller's own price
 * (my_price), sourced from their panel (cost_price).
 */
class BotService extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'panel_id',
        'provider_service_id',
        'platform',
        'category',
        'name',
        'unit_label',
        'cost_price',
        'my_price',
        'min_quantity',
        'max_quantity',
        'link_instructions',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:4',
            'my_price' => 'decimal:4',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
        ];
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(TenantPanel::class, 'panel_id');
    }
}
