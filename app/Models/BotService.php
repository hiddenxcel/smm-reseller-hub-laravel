<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A service in a reseller's own catalogue, at the reseller's own price
 * (my_price), sourced from their panel (cost_price).
 */
class BotService extends Model
{
    use BelongsToTenant, HasFactory;

    /** Sold right now. */
    public const ACTIVE = 'active';

    /** Not offered at all — the customer never sees it. */
    public const HIDDEN = 'hidden';

    /**
     * Still listed, but not taking new orders. This is the state for a panel
     * having a bad day: the service stays visible, and the bot declines rather
     * than placing an order that will fail.
     */
    public const PAUSED = 'paused';

    public const STATUSES = [self::ACTIVE, self::HIDDEN, self::PAUSED];

    protected $fillable = [
        'tenant_id',
        'panel_id',
        'provider_service_id',
        'platform',
        'category',
        'name',
        'description',
        'quality',
        'speed',
        'drop_info',
        'refill_info',
        'unit_label',
        'cost_price',
        'my_price',
        'min_quantity',
        'max_quantity',
        'link_instructions',
        'status',
        'sort_order',
        'featured',
        'requires_approval',
        'auto_paused',
        'last_synced_at',
        'synced_cost_price',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:4',
            'my_price' => 'decimal:4',
            'synced_cost_price' => 'decimal:4',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'featured' => 'boolean',
            'requires_approval' => 'boolean',
            'auto_paused' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Was this paused by the sync rather than by the reseller?
     *
     * The sync lifts its own pauses when a service reappears on the panel, and
     * must leave a deliberate pause alone — switching a service back on that
     * someone turned off on purpose is the platform overruling them.
     */
    public function wasAutoPaused(): bool
    {
        return $this->status === self::PAUSED && $this->auto_paused;
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(TenantPanel::class, 'panel_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ServicePriceHistory::class, 'service_id');
    }

    /**
     * Profit per 1,000 units — the basis prices are quoted on.
     *
     * Null rather than zero when the panel never reported a cost: an unknown
     * margin displayed as 0.00 reads as "makes nothing", which is a different
     * and much more alarming claim.
     */
    public function profit(): ?string
    {
        if ($this->cost_price === null) {
            return null;
        }

        return bcsub((string) $this->my_price, (string) $this->cost_price, 4);
    }

    /** Margin as a percentage of the selling price. */
    public function margin(): ?float
    {
        $profit = $this->profit();

        if ($profit === null || bccomp((string) $this->my_price, '0', 4) !== 1) {
            return null;
        }

        return round(((float) $profit / (float) $this->my_price) * 100, 1);
    }

    /** Losing money on every order — the thing a reseller must see first. */
    public function isUnderwater(): bool
    {
        $profit = $this->profit();

        return $profit !== null && bccomp($profit, '0', 4) === -1;
    }
}
