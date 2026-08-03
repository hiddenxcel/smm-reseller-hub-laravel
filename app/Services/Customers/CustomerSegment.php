<?php

namespace App\Services\Customers;

use App\Models\BotCustomer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What kind of customer this is — worked out from their behaviour rather than
 * stored on the row.
 *
 * Nothing here is a column, on purpose. A stored "VIP" flag is wrong the
 * moment someone crosses the threshold and nobody re-runs the job; derived
 * from spend and order count, it is right every time it is read. The cost is
 * that filtering by segment has to express the same rule in SQL, which is why
 * each segment carries its own `scope`.
 *
 * `blocked` is the exception and comes first: it IS a stored decision, and it
 * overrides everything else. A blocked customer is not shown as VIP.
 */
final class CustomerSegment
{
    public const BLOCKED = 'blocked';

    public const VIP = 'vip';

    public const NEW = 'new';

    public const RETURNING = 'returning';

    public const ACTIVE = 'active';

    /** Spend at or above this marks a customer as VIP. */
    public const VIP_SPEND = 100.0;

    /** …or this many orders, for the steady buyer who spends little each time. */
    public const VIP_ORDERS = 20;

    /** First seen within this many days is still "new". */
    public const NEW_DAYS = 7;

    /** Heard from within this many days counts as active. */
    public const ACTIVE_DAYS = 30;

    /** Every segment a customer belongs to, most defining first. */
    public static function for(BotCustomer $customer, int $orderCount): array
    {
        if ($customer->blocked_at !== null) {
            return [self::BLOCKED];
        }

        $segments = [];

        if (self::isVip($customer, $orderCount)) {
            $segments[] = self::VIP;
        }

        if ($customer->created_at !== null
            && $customer->created_at->gt(Carbon::now()->subDays(self::NEW_DAYS))) {
            $segments[] = self::NEW;
        } elseif ($orderCount > 1) {
            $segments[] = self::RETURNING;
        }

        if ($customer->last_seen_at !== null
            && $customer->last_seen_at->gt(Carbon::now()->subDays(self::ACTIVE_DAYS))) {
            $segments[] = self::ACTIVE;
        }

        return $segments === [] ? [self::RETURNING] : $segments;
    }

    public static function isVip(BotCustomer $customer, int $orderCount): bool
    {
        return (float) $customer->total_spent >= self::VIP_SPEND
            || $orderCount >= self::VIP_ORDERS;
    }

    public static function isValid(?string $segment): bool
    {
        return in_array($segment, [
            self::BLOCKED,
            self::VIP,
            self::NEW,
            self::RETURNING,
            self::ACTIVE,
        ], true);
    }

    /**
     * The same rules as SQL, for the segment filter.
     *
     * `orders_count` is a withCount subquery on the outer query, which cannot
     * be referenced in a WHERE — so the order-count half of each rule is
     * written as its own `has()` constraint rather than reusing the alias.
     */
    public static function scope(Builder $query, string $segment): void
    {
        match ($segment) {
            self::BLOCKED => $query->whereNotNull('blocked_at'),

            self::VIP => $query
                ->whereNull('blocked_at')
                ->where(fn (Builder $q) => $q
                    ->where('total_spent', '>=', self::VIP_SPEND)
                    ->orHas('orders', '>=', self::VIP_ORDERS)),

            self::NEW => $query
                ->whereNull('blocked_at')
                ->where('created_at', '>', Carbon::now()->subDays(self::NEW_DAYS)),

            self::RETURNING => $query
                ->whereNull('blocked_at')
                ->where('created_at', '<=', Carbon::now()->subDays(self::NEW_DAYS))
                ->has('orders', '>', 1),

            self::ACTIVE => $query
                ->whereNull('blocked_at')
                ->where('last_seen_at', '>', Carbon::now()->subDays(self::ACTIVE_DAYS)),

            default => null,
        };
    }
}
