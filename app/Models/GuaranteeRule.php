<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refill-guarantee keywords matched against a service name.
 * panel_id NULL = tenant-wide rule. refill_days 0 = lifetime.
 */
class GuaranteeRule extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'panel_id',
        'rule_type',
        'keyword',
        'refill_days',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'refill_days' => 'integer',
        ];
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(TenantPanel::class, 'panel_id');
    }
}
