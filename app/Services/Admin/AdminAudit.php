<?php

namespace App\Services\Admin;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Every action an admin takes, written down.
 *
 * The console can suspend a reseller, move their credit and read their
 * customers' phone numbers. None of that is reversible from the outside, so the
 * record of who did it is not optional and not a debugging aid — it is the only
 * thing that can answer a reseller asking why their account changed.
 *
 * Actions are dotted strings matching the permission they need
 * (`tenants.suspend`), so a log line and the grade that allowed it line up.
 */
class AdminAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(string $action, array $details = []): void
    {
        $admin = Auth::guard('superadmin')->user();

        ActivityLog::create([
            'actor_type' => 'superadmin',
            'actor_id' => $admin?->id,
            'action' => $action,
            // The admin's username travels with the row: roles change and rows
            // get disabled, so an id alone stops being readable over time.
            'details' => $admin
                ? ['actor' => $admin->username, ...$details]
                : $details,
            'ip' => Request::ip(),
        ]);
    }

    /**
     * An action taken against one reseller.
     *
     * Separate from record() only to make tenant_id impossible to forget — it is
     * what the tenant detail screen filters its history on.
     *
     * @param  array<string, mixed>  $details
     */
    public static function onTenant(string $action, int $tenantId, array $details = []): void
    {
        static::record($action, ['tenant_id' => $tenantId, ...$details]);
    }
}
