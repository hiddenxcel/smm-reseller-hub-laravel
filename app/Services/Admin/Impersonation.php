<?php

namespace App\Services\Admin;

use App\Models\AdminImpersonation;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Looking at the platform through one reseller's eyes, without being able to
 * change anything.
 *
 * Read-only is enforced by BlockDuringImpersonation, which refuses every
 * unsafe HTTP method rather than hiding buttons — an admin who reaches for a
 * form the UI did not draw still gets nowhere.
 *
 * The session carries the impersonation id, not just a flag, so the ending of a
 * session can always be written against the row that started it even if the
 * admin's own login expires in between.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonation_id';

    /**
     * Log the admin into the tenant guard and open a record.
     *
     * Both guards are live afterwards: the tenant guard is what the reseller's
     * own screens read, and the admin guard is what proves who is behind them.
     */
    public static function start(Superadmin $admin, Tenant $tenant, Request $request, ?string $reason = null): AdminImpersonation
    {
        // An admin already inside an account starts nothing new — the previous
        // visit is closed first, so two open rows can never describe one admin.
        static::closeOpenFor($admin->id);

        $record = AdminImpersonation::create([
            'superadmin_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'reason' => $reason,
            'ip' => $request->ip(),
            'started_at' => now(),
        ]);

        Auth::guard('tenant')->login($tenant);

        $request->session()->put(self::SESSION_KEY, $record->id);

        AdminAudit::onTenant('tenants.impersonate.start', $tenant->id, [
            'impersonation_id' => $record->id,
            'reason' => $reason,
        ]);

        return $record;
    }

    /** Close the visit and drop the tenant session, leaving the admin logged in. */
    public static function stop(Request $request): void
    {
        $id = $request->session()->pull(self::SESSION_KEY);

        if ($id !== null) {
            $record = AdminImpersonation::find($id);

            $record?->forceFill(['ended_at' => now()])->save();

            if ($record) {
                AdminAudit::onTenant('tenants.impersonate.stop', $record->tenant_id, [
                    'impersonation_id' => $record->id,
                ]);
            }
        }

        Auth::guard('tenant')->logout();
    }

    public static function isActive(Request $request): bool
    {
        return $request->session()->has(self::SESSION_KEY);
    }

    public static function current(Request $request): ?AdminImpersonation
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return $id === null ? null : AdminImpersonation::find($id);
    }

    /**
     * Close any visit left open by an admin — a browser closed mid-session
     * would otherwise leave a row that never ends and reads as still active.
     */
    public static function closeOpenFor(int $superadminId): void
    {
        AdminImpersonation::where('superadmin_id', $superadminId)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }
}
