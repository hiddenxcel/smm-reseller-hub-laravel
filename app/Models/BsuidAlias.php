<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A short stand-in for a WhatsApp business-scoped user ID. See the migration.
 *
 * The alias is derived from the BSUID, so it is the same every time and needs
 * no counter; it starts with a letter so it can never be mistaken for a phone
 * number, and is 21 characters, inside the 30 the phone columns allow.
 */
class BsuidAlias extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'bsuid', 'alias', 'username'];

    /** Does this string look like an alias rather than a phone number? */
    public static function isAlias(string $value): bool
    {
        return preg_match('/^u[0-9a-f]{20}$/', $value) === 1;
    }

    /** The alias for a BSUID, made the first time it is seen. */
    public static function aliasFor(int $tenantId, string $bsuid, ?string $username = null): string
    {
        $alias = 'u'.substr(hash('sha256', $tenantId.'|'.$bsuid), 0, 20);

        $row = static::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenantId, 'bsuid' => $bsuid],
            ['alias' => $alias, 'username' => $username !== null ? mb_substr($username, 0, 100) : null],
        );

        if ($username !== null && $row->username !== $username) {
            $row->update(['username' => mb_substr($username, 0, 100)]);
        }

        return $row->alias;
    }

    /** The BSUID behind an alias, or null when it is not one of ours. */
    public static function bsuidFor(int $tenantId, string $alias): ?string
    {
        if (! self::isAlias($alias)) {
            return null;
        }

        return static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('alias', $alias)
            ->value('bsuid');
    }
}
