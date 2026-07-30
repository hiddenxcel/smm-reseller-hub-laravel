<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reseller's WhatsApp Cloud API number. phone_number_id is UNIQUE across the
 * platform because it is the webhook router key: Meta's payload carries it,
 * and it is what maps an inbound message to a tenant.
 */
class TenantWhatsApp extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_whatsapp';

    protected $fillable = [
        'tenant_id',
        'source',
        'cloud_api_token_enc',
        'phone_number_id',
        'waba_id',
        'verify_token',
        'display_number',
        'status',
        'bot_type',
    ];

    protected $hidden = [
        'cloud_api_token_enc',
    ];

    protected function casts(): array
    {
        return [
            'cloud_api_token_enc' => 'encrypted',
        ];
    }

    /**
     * Resolve an inbound Meta webhook to a tenant's number. Crosses the tenant
     * scope deliberately: webhooks arrive with no session.
     */
    public static function findByPhoneNumberId(string $phoneNumberId): ?self
    {
        return static::withoutTenantScope()
            ->where('phone_number_id', $phoneNumberId)
            ->first();
    }
}
