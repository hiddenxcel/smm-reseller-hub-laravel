<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A reseller's Telegram bot. One per bot type, like a WhatsApp number.
 */
class TenantTelegramBot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'bot_type',
        'token_enc',
        'telegram_bot_id',
        'bot_username',
        'public_id',
        'webhook_secret',
        'status',
    ];

    protected $hidden = ['token_enc', 'webhook_secret'];

    protected function casts(): array
    {
        return ['token_enc' => 'encrypted'];
    }

    /** Resolve an inbound webhook; crosses the tenant scope as webhooks have no session. */
    public static function findByPublicId(string $publicId): ?self
    {
        return static::withoutTenantScope()->where('public_id', $publicId)->first();
    }
}
