<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a panel changed that the reseller has to allow, or has been told about.
 */
class CatalogueChange extends Model
{
    use BelongsToTenant;

    public const COST = 'cost';

    public const REMOVED = 'removed';

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'tenant_id',
        'service_id',
        'panel_id',
        'provider_service_id',
        'service_name',
        'kind',
        'status',
        'old_cost',
        'new_cost',
        'old_price',
        'proposed_price',
        'note',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'old_cost' => 'decimal:4',
            'new_cost' => 'decimal:4',
            'old_price' => 'decimal:4',
            'proposed_price' => 'decimal:4',
            'resolved_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BotService::class, 'service_id');
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(TenantPanel::class, 'panel_id');
    }
}
