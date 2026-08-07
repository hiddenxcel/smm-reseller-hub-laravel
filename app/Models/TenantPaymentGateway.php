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
        'extra_enc',
        'status',
        'is_default',
    ];

    protected $hidden = [
        'api_key_enc',
        'webhook_secret_enc',
        'extra_enc',
    ];

    protected function casts(): array
    {
        return [
            'api_key_enc' => 'encrypted',
            'webhook_secret_enc' => 'encrypted',
            'extra_enc' => 'encrypted',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Make this the gateway customers are sent to, clearing whichever held it.
     *
     * Both writes in one transaction: a partial index enforces one default per
     * tenant, so setting before clearing would collide, and clearing without
     * setting would leave a reseller with none.
     */
    public function makeDefault(): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () {
            static::withoutTenantScope()
                ->where('tenant_id', $this->tenant_id)
                ->whereKeyNot($this->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->update(['is_default' => true]);
        });
    }
}
