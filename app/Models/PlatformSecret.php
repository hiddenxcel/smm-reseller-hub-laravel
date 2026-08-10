<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A platform secret an owner can set without SSH.
 *
 * Encrypted at rest, never serialised to a response, and always beaten by the
 * matching .env value — see the migration for the whole argument.
 */
class PlatformSecret extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /**
     * The secrets that may be stored here, and where .env would put them.
     *
     * A fixed list rather than free-form: a settings screen that accepts any
     * key name is a settings screen that will eventually hold something
     * nobody meant to put in a database.
     */
    public const KEYS = [
        'platform_deepseek_key' => 'services.platform_deepseek_key',
    ];

    protected $fillable = [
        'key',
        'value_enc',
        'enabled',
        'superadmin_id',
    ];

    /**
     * Never serialised to a response, even by accident. The console sends a
     * masked hint instead — see hint().
     */
    protected $hidden = ['value_enc'];

    protected function casts(): array
    {
        return [
            'value_enc' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    private const CACHE_KEY = 'platform-secrets';

    /**
     * All rows, keyed by name.
     *
     * Cached because the assistant reads this on every question and it
     * changes only when an owner edits it. Forgotten on every write.
     *
     * @return Collection<string, self>
     */
    public static function all($columns = ['*'])
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->get()->keyBy('key'),
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

    /**
     * The value in force: environment first, database second.
     *
     * .env wins so an operator who already keeps secrets on disk — the
     * original arrangement, and the safer one — is never overridden by
     * something typed into a browser.
     *
     * A row that exists but is switched off is ignored, which is what makes
     * it safe to paste a key in and verify it before anything uses it.
     */
    public static function value(string $key): ?string
    {
        $fromEnv = config(self::KEYS[$key] ?? '');

        if (filled($fromEnv)) {
            return (string) $fromEnv;
        }

        $row = self::all()->get($key);

        return $row?->enabled && filled($row->value_enc) ? (string) $row->value_enc : null;
    }

    /**
     * Where this secret came from.
     *
     * Worth showing: an owner who pastes a key into the console and sees no
     * change needs to know an .env value is winning, rather than concluding
     * the save did not work.
     */
    public static function source(string $key): string
    {
        if (filled(config(self::KEYS[$key] ?? ''))) {
            return 'env';
        }

        $row = self::all()->get($key);

        if ($row === null || blank($row->value_enc)) {
            return 'none';
        }

        return $row->enabled ? 'database' : 'stored-disabled';
    }

    /**
     * The last four characters, and nothing else.
     *
     * Enough to tell two keys apart while rotating one; useless to anyone who
     * captures the screen.
     */
    public static function hint(string $key): ?string
    {
        $value = (string) self::value($key);

        return mb_strlen($value) > 4 ? '••••'.mb_substr($value, -4) : null;
    }
}
