<?php

namespace App\Services\Team;

use Illuminate\Support\Str;

/**
 * What each team role may do — and the single place that says so.
 *
 * Deny by default, in two layers. A route has to be on the role's list to be
 * reachable at all, and a short owner-only list overrides every role, however
 * generous: money, credentials, the account itself and the team. A route added
 * next month is therefore closed to members until someone decides otherwise,
 * which is the safe way round.
 *
 * The check is on the route and the method, not on what the screen happens to
 * show — a hidden button is not enforcement, and a stale form, a bookmark or
 * curl would all still go through.
 */
class TeamAccess
{
    public const LABELS = [
        'admin' => 'Admin',
        'support' => 'Support',
        'viewer' => 'Viewer',
    ];

    public const DESCRIPTIONS = [
        'admin' => 'Runs the shop day to day: services, customers, orders, both bots. Cannot touch billing, payment gateways, keys, or this team.',
        'support' => 'Answers customers: the support inbox and tickets, and can look up customers and orders. Changes nothing else.',
        'viewer' => 'Can look, not touch: dashboard, analytics, customers, orders, services, the bots. Cannot change or download anything.',
    ];

    /** Always reachable, whatever the role: leaving must never be blocked. */
    private const ALWAYS = ['logout'];

    /**
     * Never reachable by a member, on any method.
     *
     * Money (billing, gateways), credentials (panels, WhatsApp numbers, API
     * keys), the account itself (profile, password) and the team. Anyone who
     * could reach these could redirect the shop's payments or lock the owner
     * out.
     */
    private const OWNER_ONLY = [
        'billing', 'billing.*',
        'profile.*',
        'password.*',
        'team', 'team.*',
        'api-access', 'api-access.*',
        'help.*',
        'onboarding.payments.*',
        'onboarding.panel.*',
        'onboarding.whatsapp.*',
        'onboarding.test.golive',
        'order-bot.gateways', 'order-bot.gateways.*',
        'order-bot.providers.store',
        'order-bot.providers.destroy',
    ];

    /**
     * What an admin may work in, on any method.
     *
     * A list rather than "everything not forbidden": an area added next month
     * stays closed to admins until someone decides it belongs here, instead of
     * opening to them by default.
     */
    private const ADMIN_AREAS = [
        'dashboard',
        'analytics',
        'customers', 'customers.*',
        'orders', 'orders.*',
        'services', 'services.*',
        'order-bot', 'order-bot.*',
        'support-bot', 'support-bot.*',
        'settings', 'settings.*',
        'simulator.*',
        // The setup wizard, minus the money and credential steps that
        // OWNER_ONLY already takes back.
        'onboarding', 'onboarding.step',
        'onboarding.services.*',
        'onboarding.skip', 'onboarding.unskip',
        'onboarding.test.*',
    ];

    /** Pages a viewer may open. Read only, so GET and nothing else. */
    private const VIEWER_PAGES = [
        'dashboard',
        'analytics',
        'customers.index',
        'customers.show',
        'orders.index',
        'services.index',
        'services.show',
        'order-bot',
        'order-bot.inbox',
        'support-bot',
        'support-bot.inbox',
        'support-bot.tickets',
        'support-bot.tickets.show',
    ];

    /** Pages support may open, read only. */
    private const SUPPORT_PAGES = [
        'dashboard',
        'customers.index',
        'customers.show',
        'orders.index',
        'order-bot.inbox',
        'support-bot',
    ];

    /** What support may also do, on any method: reply and manage tickets. */
    private const SUPPORT_ACTIONS = [
        'support-bot.inbox', 'support-bot.inbox.*',
        'support-bot.tickets', 'support-bot.tickets.*',
    ];

    /** Every nav row the sidebar can show, so it can hide what a member cannot open. */
    public const NAV_ROUTES = [
        'dashboard', 'analytics', 'billing', 'settings', 'api-access', 'team', 'help.support',
        'order-bot', 'order-bot.inbox', 'order-bot.providers', 'order-bot.payments', 'order-bot.gateways',
        'customers.index', 'services.index', 'orders.index',
        'support-bot', 'support-bot.inbox', 'support-bot.tickets',
        'profile.edit',
    ];

    public static function isValidRole(string $role): bool
    {
        return isset(self::LABELS[$role]);
    }

    /**
     * May this role make this request?
     *
     * @param  string|null  $action  the `action` field of a POST, for the one
     *                               route that multiplexes several things
     */
    public static function allows(string $role, ?string $routeName, string $method, ?string $action = null): bool
    {
        if ($routeName === null || ! self::isValidRole($role)) {
            return false;
        }

        if (self::matches(self::ALWAYS, $routeName)) {
            return true;
        }

        if (self::matches(self::OWNER_ONLY, $routeName)) {
            return false;
        }

        $read = in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);

        return match ($role) {
            'admin' => self::matches(self::ADMIN_AREAS, $routeName)
                && ! self::isWalletAdjustment($routeName, $action),
            'support' => ($read && self::matches(self::SUPPORT_PAGES, $routeName))
                || self::matches(self::SUPPORT_ACTIONS, $routeName),
            'viewer' => $read && self::matches(self::VIEWER_PAGES, $routeName),
            default => false,
        };
    }

    /**
     * The nav rows a role can open, for hiding the rest.
     *
     * @return array<int, string>
     */
    public static function visibleNav(string $role): array
    {
        return array_values(array_filter(
            self::NAV_ROUTES,
            fn (string $name) => self::allows($role, $name, 'GET'),
        ));
    }

    /**
     * Crediting a customer's wallet is money, even though it rides on the same
     * route as blocking or messaging them — so an admin may do the rest of
     * `customers.act` but not this.
     */
    private static function isWalletAdjustment(string $routeName, ?string $action): bool
    {
        return $routeName === 'customers.act' && $action === 'wallet';
    }

    /** @param  array<int, string>  $patterns */
    private static function matches(array $patterns, string $routeName): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
