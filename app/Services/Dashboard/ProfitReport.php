<?php

namespace App\Services\Dashboard;

use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * What the reseller actually earns, as opposed to what they turn over.
 *
 * Revenue is the figure the dashboard led with, and it is the misleading one:
 * an SMM reseller buys at the panel's price and sells at their own, so a
 * month of heavy trading on a badly-priced service can grow revenue while
 * losing money on every order.
 *
 * Two different questions are answered here, and they are deliberately not
 * mixed:
 *
 *   realised — margin on orders actually placed, from the cost snapshotted on
 *              each one. This is money already made or lost.
 *   priced   — margin implied by the catalogue as it stands right now. This is
 *              money that will be made or lost on the next order, and it is
 *              the one a reseller can still do something about.
 *
 * Orders placed before cost snapshotting existed carry no `charge`, so they
 * are excluded from the realised figures rather than counted as pure profit.
 * `coverage` reports how much of the period could be measured, because a
 * margin drawn from a fifth of the orders needs saying so.
 */
class ProfitReport
{
    /** Matches the KPI window on the dashboard, so the two agree. */
    public const WINDOW_DAYS = 30;

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * Headline profit for the window, with the previous equal period to
     * compare against.
     */
    public function summary(): array
    {
        $now = Carbon::now();
        $windowStart = $now->copy()->subDays(self::WINDOW_DAYS - 1)->startOfDay();
        $priorStart = $windowStart->copy()->subDays(self::WINDOW_DAYS);

        $current = $this->totalsBetween($windowStart, $now);
        $prior = $this->totalsBetween($priorStart, $windowStart->copy()->subSecond());

        return [
            'revenue' => $current['revenue'],
            'cost' => $current['cost'],
            'profit' => $current['profit'],
            'margin' => $this->marginOf($current['revenue'], $current['profit']),
            'previousProfit' => $prior['profit'],
            'delta' => $this->percentChange($prior['profit'], $current['profit']),
            'measuredOrders' => $current['measured'],
            'totalOrders' => $current['total'],
            // A whole percentage: it qualifies a figure rather than being one,
            // and "based on 24.7% of orders" is false precision.
            'coverage' => $current['total'] === 0
                ? null
                : (int) round(($current['measured'] / $current['total']) * 100),
        ];
    }

    /**
     * Profit per service over the window, worst margin first.
     *
     * Ordered by margin rather than by profit on purpose: the service quietly
     * losing a few cents on every order is the one to fix, and sorting by
     * total would bury it under the bestsellers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byService(int $limit = 8): array
    {
        $rows = BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', Carbon::now()->subDays(self::WINDOW_DAYS - 1)->startOfDay())
            ->whereNotNull('service_name')
            ->whereNotNull('charge')
            ->where('payment_status', 'paid')
            ->selectRaw(
                'service_name,'
                .' count(*) as orders,'
                .' coalesce(sum(amount), 0) as revenue,'
                .' coalesce(sum(charge), 0) as cost'
            )
            ->groupBy('service_name')
            ->get();

        return $rows
            ->map(function ($row) {
                $revenue = round((float) $row->revenue, 2);
                $cost = round((float) $row->cost, 2);
                $profit = round($revenue - $cost, 2);

                return [
                    'name' => $row->service_name,
                    'orders' => (int) $row->orders,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin' => $this->marginOf($revenue, $profit),
                ];
            })
            ->sortBy('margin')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Services priced at or below what the panel charges for them.
     *
     * Read from the catalogue, not from orders: a service priced underwater
     * that nobody has ordered yet is exactly the one worth catching, and it
     * has no order history to appear in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function underwater(int $limit = 5): array
    {
        return BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('status', BotService::ACTIVE)
            ->whereNotNull('cost_price')
            ->whereColumn('my_price', '<=', 'cost_price')
            ->orderByRaw('(my_price - cost_price) asc')
            ->limit($limit)
            ->get()
            ->map(fn (BotService $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'platform' => $service->platform,
                'myPrice' => (float) $service->my_price,
                'costPrice' => (float) $service->cost_price,
                'lossPerThousand' => round((float) $service->my_price - (float) $service->cost_price, 4),
            ])
            ->all();
    }

    // ---- internals -------------------------------------------------------

    /**
     * Revenue, cost and profit for orders in a period.
     *
     * Only paid orders count. An unpaid order cost the reseller nothing and
     * earned them nothing, and counting its would-be margin would show profit
     * on money that never arrived.
     *
     * @return array{revenue: float, cost: float, profit: float, measured: int, total: int}
     */
    private function totalsBetween(Carbon $from, Carbon $to): array
    {
        $base = BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to]);

        $measured = (clone $base)->whereNotNull('charge');

        $revenue = round((float) (clone $measured)->sum('amount'), 2);
        $cost = round((float) (clone $measured)->sum('charge'), 2);

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => round($revenue - $cost, 2),
            'measured' => (clone $measured)->count(),
            'total' => $base->count(),
        ];
    }

    /** Margin as a percentage of revenue; null when nothing was sold. */
    private function marginOf(float $revenue, float $profit): ?float
    {
        if ($revenue === 0.0) {
            return null;
        }

        return round(($profit / $revenue) * 100, 1);
    }

    /**
     * Null when there is no prior figure to compare against.
     *
     * Also null when the prior period lost money: "up 300% from a loss" is
     * arithmetic, not information, and the sign flip makes it actively
     * misleading.
     */
    private function percentChange(float $from, float $to): ?float
    {
        if ($from <= 0.0) {
            return null;
        }

        return round((($to - $from) / $from) * 100, 1);
    }
}
