<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reseller's own SMM panel. api_key_enc is encrypted at rest via Laravel's
 * Crypt (the old platform used a hand-rolled AES-256-GCM envelope; there is
 * no data to carry over, so we adopt the framework's).
 */
class TenantPanel extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'panel_type',
        'api_url',
        'api_key_enc',
        'api_version',
        'auth_method',
        'last_checked_at',
        'last_balance',
        'balance_currency',
        'services_count',
        'status',
    ];

    protected $hidden = [
        'api_key_enc',
    ];

    protected function casts(): array
    {
        return [
            'api_key_enc' => 'encrypted',
            'last_checked_at' => 'datetime',
            'last_balance' => 'decimal:2',
        ];
    }
}
