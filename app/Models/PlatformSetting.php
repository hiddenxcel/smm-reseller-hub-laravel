<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Platform-wide settings, editable without a deploy.
 *
 * Never credentials — see the migration for why. Company copy and small
 * business numbers only.
 */
class PlatformSetting extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'superadmin_id', 'updated_at'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    private const CACHE_KEY = 'platform-settings';

    /**
     * What a setting is when nobody has changed it.
     *
     * The defaults live here rather than in config so the screen has something
     * to show for a key that has never been written, and so a fresh install
     * behaves like a configured one.
     */
    public const DEFAULTS = [
        'company_name' => 'Resellers Hub',
        'support_email' => '',
        'support_whatsapp' => '',
        'website_url' => '',
        // The cut a referrer earns of what they refer, as a percentage.
        'referral_percent' => 10,
        // Shown to resellers on the billing screen.
        'terms_url' => '',
        'privacy_url' => '',
        // Turns off new reseller registration without a deploy — the switch
        // worth having when something is on fire.
        'registration_open' => true,
    ];

    /**
     * Every setting, stored values over defaults.
     *
     * Named `values()` rather than `all()`: Eloquent's own all() returns a
     * Collection of models, and shadowing it with a different return type would
     * be a trap for the next caller.
     *
     * Cached because the dashboard and the bots both read it; the cache is
     * cleared on write rather than expiring, so a change is immediate.
     *
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = static::query()->pluck('value', 'key')->all();

            // Only NULL falls through to the default. An empty string is a real
            // stored value — a deliberately cleared URL — and filtering it out
            // would resurrect the default on the next read, so a cleared field
            // would look changed on every save and never stay cleared.
            return [...self::DEFAULTS, ...array_filter(
                $stored,
                fn ($value) => $value !== null,
            )];
        });
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        return self::values()[$key] ?? $fallback ?? self::DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, mixed $value, ?int $superadminId = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'superadmin_id' => $superadminId,
                'updated_at' => now(),
            ],
        );

        Cache::forget(self::CACHE_KEY);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
