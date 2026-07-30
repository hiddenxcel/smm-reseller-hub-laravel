<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reseller's OWN gateway credentials — their customers pay them through it.
 * Separate from the platform's gateways in config, which resellers use to pay
 * the platform.
 */
class TenantPaymentGateway extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'gateway',
        'api_key_enc',
        'webhook_secret_enc',
        'status',
    ];

    protected $hidden = [
        'api_key_enc',
        'webhook_secret_enc',
    ];

    protected function casts(): array
    {
        return [
            'api_key_enc' => 'encrypted',
            'webhook_secret_enc' => 'encrypted',
        ];
    }
}
