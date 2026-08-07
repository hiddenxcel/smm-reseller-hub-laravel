<?php

namespace App\Services\Admin;

use App\Models\ActivityLog;
use App\Models\AdminImpersonation;
use App\Models\BlockedIp;
use App\Models\Superadmin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * What is happening at the edges of the platform.
 *
 * Reported from what is already recorded — admin logins, impersonations and
 * the blocked list — rather than from a new tracking table. Failed logins are
 * the one gap: Laravel's throttle counts them in the cache, which is the right
 * place for rate limiting and the wrong place to report from, so what this
 * screen shows is who DID get in and from where. An unexpected address on a
 * successful login is the signal worth having; a wrong password is noise.
 */
class SecurityOverview
{
    public static function make(): self
    {
        return new self;
    }

    public function kpis(): array
    {
        return [
            'admins' => Superadmin::count(),
            'activeAdmins' => Superadmin::where('status', 'active')->count(),
            'blockedIps' => BlockedIp::live()->count(),
            'impersonations7d' => AdminImpersonation::where('started_at', '>=', now()->subDays(7))
                ->count(),
            // An impersonation with no end is either in progress or a session
            // that died mid-visit. Either way it wants looking at.
            'openImpersonations' => AdminImpersonation::whereNull('ended_at')->count(),
            'adminActions7d' => ActivityLog::where('actor_type', 'superadmin')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ];
    }

    /**
     * Where admins have signed in from.
     *
     * The list a compromise shows up in: an admin's usual address is stable, so
     * a new one next to a familiar username is the thing to notice.
     */
    public function adminLogins(int $limit = 20): array
    {
        return ActivityLog::where('actor_type', 'superadmin')
            ->where('action', 'admin.login')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $entry) => [
                'id' => $entry->id,
                'admin' => $entry->details['actor'] ?? null,
                'ip' => $entry->ip,
                'at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Every admin's last sign-in, so a dormant account stands out. */
    public function adminAccess(): array
    {
        return Superadmin::orderByDesc('last_login_at')
            ->get()
            ->map(fn (Superadmin $admin) => [
                'id' => $admin->id,
                'username' => $admin->username,
                'role' => $admin->role,
                'status' => $admin->status,
                'lastLoginAt' => $admin->last_login_at?->toIso8601String(),
                'lastLoginIp' => $admin->last_login_ip,
                // Never signed in, or not for a long time. Both are accounts
                // worth asking about.
                'dormant' => $admin->last_login_at === null
                    || $admin->last_login_at->lessThan(now()->subDays(60)),
            ])
            ->all();
    }

    /** Recent visits into reseller accounts, with who and why. */
    public function impersonations(int $limit = 20): array
    {
        return AdminImpersonation::with(['superadmin', 'tenant'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AdminImpersonation $visit) => [
                'id' => $visit->id,
                'admin' => $visit->superadmin?->username ?? 'Deleted admin',
                'tenantId' => $visit->tenant_id,
                'tenant' => $visit->tenant?->business_name ?? 'Deleted reseller',
                'reason' => $visit->reason,
                'ip' => $visit->ip,
                'startedAt' => $visit->started_at?->toIso8601String(),
                'endedAt' => $visit->ended_at?->toIso8601String(),
                'open' => $visit->isOpen(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function blockedIps(): array
    {
        return BlockedIp::with('blockedBy')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BlockedIp $blocked) => [
                'id' => $blocked->id,
                'ip' => $blocked->ip,
                'reason' => $blocked->reason,
                'by' => $blocked->blockedBy?->username,
                'expiresAt' => $blocked->expires_at?->toIso8601String(),
                'expired' => $blocked->hasExpired(),
                'at' => $blocked->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Block an address.
     *
     * Refuses the caller's own address: an admin who blocks the office IP from
     * the office loses the console, and the fix is a database edit.
     */
    public static function block(string $ip, ?string $reason, ?Carbon $until): bool
    {
        if ($ip === request()->ip()) {
            return false;
        }

        BlockedIp::updateOrCreate(
            ['ip' => $ip],
            [
                'reason' => $reason,
                'superadmin_id' => Auth::guard('superadmin')->id(),
                'expires_at' => $until,
            ],
        );

        BlockedIp::forget($ip);

        AdminAudit::record('security.block_ip', [
            'ip' => $ip,
            'reason' => $reason,
            'until' => $until?->toIso8601String(),
        ]);

        return true;
    }

    public static function unblock(BlockedIp $blocked): void
    {
        $ip = $blocked->ip;

        $blocked->delete();
        BlockedIp::forget($ip);

        AdminAudit::record('security.unblock_ip', ['ip' => $ip]);
    }
}
