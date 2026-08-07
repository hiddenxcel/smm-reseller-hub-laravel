<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded price change. Append-only — nothing edits or deletes these,
 * which is what makes them worth reading.
 */
class ServicePriceHistory extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'service_price_history';

    public const UPDATED_AT = null;

    /** Why a price moved. */
    public const MANUAL = 'manual';

    public const BULK = 'bulk';

    public const RULE = 'rule';

    public const SYNC = 'sync';

    public const IMPORT = 'import';

    protected $fillable = [
        'tenant_id',
        'service_id',
        'reason',
        'old_price',
        'new_price',
        'old_cost',
        'new_cost',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:4',
            'new_price' => 'decimal:4',
            'old_cost' => 'decimal:4',
            'new_cost' => 'decimal:4',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BotService::class, 'service_id');
    }
}
