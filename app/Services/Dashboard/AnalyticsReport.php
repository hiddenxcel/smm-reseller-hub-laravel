<?php

namespace App\Services\Dashboard;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The longer view behind the dashboard: how the shop is trending over a week,
 * a month or a quarter, and when and through what its customers buy.
 *
 * Built from the same tables and the same definitions as DashboardMetrics, so
 * the two pages can never give different answers to "how much did I take?":
 * revenue is money customers actually paid, an order is "completed" by the same
 * rule, and everything is scoped by tenant explicitly rather than leaning on
 * the global scope — an aggregate with a missing scope leaks another
 * reseller's numbers without a single row being shown.
 *
 * Every figure covers the chosen window and, where a comparison means
 * something, the equal-length window before it.
 */
class AnalyticsReport
{
    /** The windows a reseller can ask for, in days. */
    public const RANGES = [7, 30, 90];

    public const DEFAULT_RANGE = 30;

    private Carbon $from;

    private Carbon $to;

    private Carbon $priorFrom;

    private Carbon $priorTo;

    public function __construct(private Tenant $tenant, private int $days)
    {
        $this->to = Carbon::now();
        $this->from = Carbon::today()->subDays($days - 1);
        $this->priorTo = $this->from->copy()->subSecond();
        $this->priorFrom = $this->from->copy()->subDays($days);
    }

    public static function for(Tenant $tenant, int $days = self::DEFAULT_RANGE): self
    {
        return new self($tenant, in_array($days, self::RANGES, true) ? $days : self::DEFAULT_RANGE);
    }

    public function days(): int
    {
        return $this->days;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $revenue = $this->revenueBetween($this->from, $this->to);
        $priorRevenue = $this->revenueBetween($this->priorFrom, $this->priorTo);

        $orders = $this->orders($this->from, $this->to);
        $priorOrders = $this->orders($this->priorFrom, $this->priorTo)->count();

        $completed = $orders->filter(fn (BotOrder $order) => $this->fold($order) === 'completed')->count();

        $customers = $this->newCustomers($this->from, $this->to);
        $priorCustomers = $this->newCustomers($this->priorFrom, $this->priorTo);

        // The average is over orders that have a price: an order with no
        // amount is a gap in the data, not a free order, and counting it as
        // zero would drag the average down for no reason.
        $priced = $orders->filter(fn (BotOrder $order) => $order->amount !== null);

        return [
            'revenue' => [
                'value' => $revenue,
                'delta' => $this->percentChange($priorRevenue, $revenue),
            ],
            'orders' => [
                'value' => $orders->count(),
                'delta' => $this->percentChange($priorOrders, $orders->count()),
            ],
            'averageOrder' => $priced->isEmpty()
                ? null
                : round((float) $priced->avg(fn (BotOrder $order) => (float) $order->amount), 2),
            // Of the orders placed, the share that ended well. Null with no
            // orders — "0% completed" would read as a failure, not as nothing.
            'completionRate' => $orders->isEmpty() ? null : (int) round($completed / $orders->count() * 100),
            'newCustomers' => [
                'value' => $customers,
                'delta' => $this->percentChange($priorCustomers, $customers),
            ],
            'repeatRate' => $this->repeatRate($orders),
            'profit' => $this->profit($orders),
        ];
    }

    /**
     * Daily revenue and orders. Quiet days stay in at zero — a line that skips
     * them compresses time and overstates how busy a slow week was.
     *
     * @return array<int, array{date: string, revenue: float, orders: int}>
     */
    public function trend(): array
    {
        $revenueByDay = DB::table('bot_payments')
            ->where('tenant_id', $this->tenant->id)
            ->where('status', 'success')
            ->where('created_at', '>=', $this->from)
            ->selectRaw($this->dateExpression().' as day, coalesce(sum(amount), 0) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $ordersByDay = DB::table('bot_orders')
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $this->from)
            ->selectRaw($this->dateExpression().' as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];

        for ($day = 0; $day < $this->days; $day++) {
            $date = $this->from->copy()->addDays($day)->toDateString();

            $series[] = [
                'date' => $date,
                'revenue' => round((float) ($revenueByDay[$date] ?? 0), 2),
                'orders' => (int) ($ordersByDay[$date] ?? 0),
            ];
        }

        return $series;
    }

    /** @return array{completed: int, pending: int, failed: int} */
    public function statusMix(): array
    {
        $counts = $this->orders($this->from, $this->to)
            ->groupBy(fn (BotOrder $order) => $this->fold($order))
            ->map->count();

        return [
            'completed' => (int) ($counts['completed'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
        ];
    }

    /** @return array<int, array{name: string, orders: int, revenue: float}> */
    public function topServices(int $limit = 8): array
    {
        return $this->orders($this->from, $this->to)
            ->filter(fn (BotOrder $order) => filled($order->service_name))
            ->groupBy('service_name')
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'orders' => $group->count(),
                'revenue' => round((float) $group->sum(fn (BotOrder $order) => (float) $order->amount), 2),
            ])
            ->sortByDesc('revenue')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * When orders arrive, by weekday and by hour — so a reseller knows when a
     * bot that answers on its own matters most, and when staff should be
     * around for the handoffs.
     *
     * @return array{weekdays: array<int, array{label: string, orders: int}>, hours: array<int, array{hour: int, orders: int}>}
     */
    public function timing(): array
    {
        $orders = $this->orders($this->from, $this->to);

        $weekdays = array_fill(1, 7, 0);
        $hours = array_fill(0, 24, 0);

        foreach ($orders as $order) {
            if ($order->created_at === null) {
                continue;
            }

            // isoWeekday: Monday = 1 … Sunday = 7, so the week starts where
            // a working week does.
            $weekdays[$order->created_at->isoWeekday()]++;
            $hours[(int) $order->created_at->format('G')]++;
        }

        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return [
            'weekdays' => collect($weekdays)
                ->map(fn (int $count, int $day) => ['label' => $labels[$day], 'orders' => $count])
                ->values()
                ->all(),
            'hours' => collect($hours)
                ->map(fn (int $count, int $hour) => ['hour' => $hour, 'orders' => $count])
                ->values()
                ->all(),
        ];
    }

    /**
     * Where the money came in from, by gateway.
     *
     * @return array<int, array{gateway: string, payments: int, revenue: float}>
     */
    public function gateways(): array
    {
        return BotPayment::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('status', 'success')
            ->whereBetween('created_at', [$this->from, $this->to])
            ->selectRaw('gateway, count(*) as payments, coalesce(sum(amount), 0) as revenue')
            ->groupBy('gateway')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'gateway' => (string) $row->gateway,
                'payments' => (int) $row->payments,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * The customers worth knowing by name: who bought most in the window.
     *
     * @return array<int, array{name: string|null, phone: string, orders: int, spent: float}>
     */
    public function topCustomers(int $limit = 5): array
    {
        $rows = $this->orders($this->from, $this->to)
            ->groupBy('customer_phone')
            ->map(fn (Collection $group, string $phone) => [
                'phone' => $phone,
                'customerId' => $group->first()->customer_id,
                'orders' => $group->count(),
                'spent' => round((float) $group->sum(fn (BotOrder $order) => (float) $order->amount), 2),
            ])
            ->sortByDesc('spent')
            ->take($limit)
            ->values();

        $names = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('id', $rows->pluck('customerId')->filter()->all())
            ->pluck('name', 'id');

        return $rows
            ->map(fn (array $row) => [
                'name' => $row['customerId'] ? ($names[$row['customerId']] ?? null) : null,
                'phone' => $row['phone'],
                'orders' => $row['orders'],
                'spent' => $row['spent'],
            ])
            ->all();
    }

    /**
     * How much the bots talked, in each direction.
     *
     * @return array{received: int, sent: int}
     */
    public function messages(): array
    {
        $counts = DB::table('bot_messages')
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $this->from)
            ->selectRaw('direction, count(*) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return [
            'received' => (int) ($counts['in'] ?? 0),
            'sent' => (int) ($counts['out'] ?? 0),
        ];
    }

    // ---- internals -------------------------------------------------------

    /** @return Collection<int, BotOrder> */
    private function orders(Carbon $from, Carbon $to): Collection
    {
        // Only the columns this report reads: a quarter of a busy shop's orders
        // is a lot of rows, and the long text columns are not needed.
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->get([
                'id',
                'customer_id',
                'customer_phone',
                'service_name',
                'amount',
                'charge',
                'quantity',
                'status',
                'payment_status',
                'created_at',
            ]);
    }

    /**
     * Money customers actually paid: successful top-ups and direct order
     * payments. Wallet spend is excluded — that money was counted when it was
     * deposited, and counting both would double it.
     */
    private function revenueBetween(Carbon $from, Carbon $to): float
    {
        return round((float) BotPayment::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('status', 'success')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount'), 2);
    }

    private function newCustomers(Carbon $from, Carbon $to): int
    {
        return BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /** Of the people who ordered, the share who ordered more than once. */
    private function repeatRate(Collection $orders): ?int
    {
        $byCustomer = $orders->groupBy('customer_phone');

        if ($byCustomer->isEmpty()) {
            return null;
        }

        $repeat = $byCustomer->filter(fn (Collection $group) => $group->count() > 1)->count();

        return (int) round($repeat / $byCustomer->count() * 100);
    }

    /**
     * What was kept, on the orders that have a recorded cost. Null when none
     * do — older orders predate cost tracking, and a profit figure built from
     * nothing would be a confident zero.
     *
     * @return array{value: float, margin: int|null}|null
     */
    private function profit(Collection $orders): ?array
    {
        $measured = $orders->filter(
            fn (BotOrder $order) => $order->payment_status === 'paid'
                && $order->amount !== null
                && $order->charge !== null,
        );

        if ($measured->isEmpty()) {
            return null;
        }

        $revenue = (float) $measured->sum(fn (BotOrder $order) => (float) $order->amount);
        $cost = (float) $measured->sum(fn (BotOrder $order) => (float) $order->charge);

        return [
            'value' => round($revenue - $cost, 2),
            'margin' => $revenue > 0 ? (int) round(($revenue - $cost) / $revenue * 100) : null,
        ];
    }

    /** Same folding as the dashboard: unpaid and refunded orders are not "in progress". */
    private function fold(BotOrder $order): string
    {
        if ($order->payment_status === 'failed') {
            return 'failed';
        }

        $status = strtolower((string) $order->status);

        return match (true) {
            str_contains($status, 'complet') => 'completed',
            str_contains($status, 'cancel'),
            str_contains($status, 'fail'),
            str_contains($status, 'refund') => 'failed',
            default => 'pending',
        };
    }

    /** Null when there is nothing earlier to compare against. */
    private function percentChange(float|int $from, float|int $to): ?float
    {
        if ((float) $from === 0.0) {
            return null;
        }

        return round((($to - $from) / $from) * 100, 1);
    }

    /** Postgres runs the app; SQLite runs the tests — they spell date truncation differently. */
    private function dateExpression(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(created_at, 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', created_at)";
    }
}
