<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The platform's own merchant credentials — how resellers pay us.
 *
 * Distinct from TenantPaymentGateway, which holds a reseller's keys for
 * taking money from THEIR customers. Same providers, opposite direction;
 * conflating them would route a subscription payment into the payer's own
 * account.
 */
class PlatformGatewayCredential extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'gateway';

    protected $keyType = 'string';

    protected $fillable = [
        'gateway',
        'api_key_enc',
        'webhook_secret_enc',
        'extra_enc',
        'enabled',
        'superadmin_id',
    ];

    /**
     * Never serialised to a response, even by accident. The admin screen sends
     * a masked hint instead — see GatewayCredentials::hint().
     */
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
            'enabled' => 'boolean',
        ];
    }

    private const CACHE_KEY = 'platform-gateway-credentials';

    /**
     * All rows, keyed by gateway.
     *
     * Cached because this is read on every checkout and the set changes only
     * when an owner edits it. Forgotten on every write.
     *
     * @return \Illuminate\Support\Collection<string, self>
     */
    public static function all($columns = ['*'])
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->get()->keyBy('gateway'),
        );
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }
}
