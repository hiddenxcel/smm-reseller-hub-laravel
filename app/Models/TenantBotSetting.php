<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant, per-bot configuration as jsonb: shop details, staff numbers,
 * spam thresholds, and the sandbox test-number allow-list.
 */
class TenantBotSetting extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'bot_type',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
