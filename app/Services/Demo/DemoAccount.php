<?php

namespace App\Services\Demo;

use App\Models\Tenant;

/**
 * Who the demo account is, answered in one place.
 *
 * The middleware, the banner, the reset command and the tests all need this
 * question settled the same way. Spread across four call sites it becomes four
 * chances to compare an email case-sensitively in one of them and leave the
 * account writable.
 */
class DemoAccount
{
    /** Configured email, normalised. Empty string when there is no demo. */
    public static function email(): string
    {
        return mb_strtolower(trim((string) config('demo.email')));
    }

    /** Whether this install has a demo account at all. */
    public static function isConfigured(): bool
    {
        return self::email() !== '';
    }

    /**
     * Whether this user is the demo tenant.
     *
     * Takes any authenticatable, not a Tenant: the middleware runs on the
     * whole web group, and on an admin route `$request->user()` resolves to a
     * Superadmin. A Tenant-only signature turns every one of those requests
     * into a 500.
     *
     * Anything that is not a Tenant is not the demo, which is the right answer
     * rather than merely a safe one — a superadmin holds their own session and
     * the console is not what this lock protects.
     *
     * Compared case-insensitively: the address is typed into a login form and
     * into .env by different people on different days, and a capital letter in
     * one of them must not quietly unlock the account.
     */
    public static function is(mixed $user): bool
    {
        if (! $user instanceof Tenant || ! self::isConfigured()) {
            return false;
        }

        return mb_strtolower(trim((string) $user->email)) === self::email();
    }

    /** The demo tenant row, or null when there is none. */
    public static function tenant(): ?Tenant
    {
        if (! self::isConfigured()) {
            return null;
        }

        return Tenant::query()->whereRaw('lower(email) = ?', [self::email()])->first();
    }
}
