<?php

namespace App\Services\Dashboard;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the dashboard shows, read straight from the tenant's own data.
 *
 * All of it is scoped by tenant_id explicitly rather than leaning on the
 * global scope: these are aggregates, and a missing scope on an aggregate
 * leaks another reseller's numbers without any row ever being displayed.
 */
class DashboardMetrics
{
    /** How many days the trend charts cover. */
    public const TREND_DAYS = 14;

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * The KPI row. Each figure carries the previous equal-length period so the
     * tile can show a delta — a number with nothing to compare it to tells a
     * reseller very little.
     */
    public function kpis(): array
    {
        $now = Carbon::now();
        $windowStart = $now->copy()->subDays(29)->startOfDay();
        $priorStart = $now->copy()->subDays(59)->startOfDay();
        $priorEnd = $windowStart->copy()->subSecond();

        $revenue = $this->revenueBetween($windowStart, $now);
        $priorRevenue = $this->revenueBetween($priorStart, $priorEnd);

        $orders = $this->ordersBetween($windowStart, $now);
        $priorOrders = $this->ordersBetween($priorStart, $priorEnd);

        $customers = $this->customersBetween($windowStart, $now);
        $priorCustomers = $this->customersBetween($priorStart, $priorEnd);

        return [
            'revenue' => [
                'value' => $revenue,
                'previous' => $priorRevenue,
                'delta' => $this->percentChange($priorRevenue, $revenue),
            ],
            'orders' => [
                'value' => $orders,
                'previous' => $priorOrders,
                'delta' => $this->percentChange($priorOrders, $orders),
            ],
            'customers' => [
                'value' => $customers,
                'previous' => $priorCustomers,
                'delta' => $this->percentChange($priorCustomers, $customers),
                'total' => BotCustomer::withoutTenantScope()
                    ->where('tenant_id', $this->tenant->id)
                    ->count(),
            ],
            'walletsHeld' => $this->walletsHeld(),
        ];
    }

    /**
     * Whether the bots are actually working, as opposed to merely configured.
     *
     * "Connected" is not the same as "answering" — a number can be attached
     * with an expired token and look perfectly healthy on a settings page, so
     * this leans on whether it has replied recently.
     *
     * Reported per bot. The reseller sells the order bot and the support bot
     * separately, on separate numbers and separate subscriptions, so a single
     * "bot: online" would claim both were fine when only one was.
     */
    public function botStatus(): array
    {
        $numbers = $this->tenant->whatsAppNumbers()->get();

        return [
            'order' => $this->statusForBot('order', $numbers),
            'support' => $this->statusForBot('support', $numbers),
            'numbersConnected' => $numbers->count(),
            'messagesToday' => DB::table('bot_messages')
                ->where('tenant_id', $this->tenant->id)
                ->where('created_at', '>=', Carbon::today())
                ->count(),
        ];
    }

    /**
     * @param  Collection<int, TenantWhatsApp>  $numbers
     */
    private function statusForBot(string $bot, Collection $numbers): array
    {
        // A 'both' number serves this bot as well as the other one.
        $serving = $numbers->filter(
            fn (TenantWhatsApp $number) => $number->bot_type === $bot || $number->bot_type === 'both',
        );

        $lastReply = DB::table('bot_messages')
            ->where('tenant_id', $this->tenant->id)
            ->where('direction', 'out')
            ->where('bot_type', $bot)
            ->max('created_at');

        $lastReplyAt = $lastReply ? Carbon::parse($lastReply) : null;

        return [
            'numbers' => $serving
                ->map(fn (TenantWhatsApp $number) => [
                    'id' => $number->id,
                    'display' => $number->display_number ?? $number->phone_number_id,
                    // 'both' is worth showing: it explains why one number
                    // appears under two bots.
                    'shared' => $number->bot_type === 'both',
                ])
                ->values()
                ->all(),
            'numbersConnected' => $serving->count(),
            'lastReplyAt' => $lastReplyAt?->toIso8601String(),
            'state' => $this->botState($serving->count(), $lastReplyAt),
            'messagesToday' => DB::table('bot_messages')
                ->where('tenant_id', $this->tenant->id)
                ->where('bot_type', $bot)
                ->where('created_at', '>=', Carbon::today())
                ->count(),
        ];
    }

    /**
     * Daily revenue and order counts for the trend charts.
     *
     * Days with no activity still appear, at zero — a line that skips empty
     * days compresses time and overstates how busy a quiet week was.
     *
     * @return array<int, array{date: string, revenue: float, orders: int}>
     */
    public function trend(): array
    {
        $start = Carbon::today()->subDays(self::TREND_DAYS - 1);

        $revenueByDay = $this->dailyTotals(
            'bot_payments',
            'amount',
            $start,
            fn ($query) => $query->where('status', 'success'),
        );

        $ordersByDay = $this->dailyCounts('bot_orders', $start);

        $series = [];

        for ($day = 0; $day < self::TREND_DAYS; $day++) {
            $date = $start->copy()->addDays($day)->toDateString();

            $series[] = [
                'date' => $date,
                'revenue' => round((float) ($revenueByDay[$date] ?? 0), 2),
                'orders' => (int) ($ordersByDay[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Order outcomes, folded into the three states a reseller acts on.
     *
     * Panels each spell their statuses differently ("In progress", "Processing",
     * "Partial"), so the raw string is mapped rather than displayed.
     */
    public function orderStatusMix(): array
    {
        $counts = BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', Carbon::today()->subDays(29))
            ->get(['status', 'payment_status'])
            ->groupBy(fn (BotOrder $order) => $this->foldStatus($order))
            ->map->count();

        return [
            'completed' => (int) ($counts['completed'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
        ];
    }

    /** The reseller's best-selling services, by revenue. */
    public function topServices(int $limit = 5): array
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', Carbon::today()->subDays(29))
            ->whereNotNull('service_name')
            ->selectRaw('service_name, count(*) as orders, coalesce(sum(amount), 0) as revenue')
            ->groupBy('service_name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->service_name,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /** Panel balance — the thing that silently stops a shop when it runs out. */
    public function panels(): array
    {
        return TenantPanel::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->get()
            ->map(fn (TenantPanel $panel) => [
                'id' => $panel->id,
                'name' => $panel->name,
                'balance' => $panel->last_balance !== null ? (float) $panel->last_balance : null,
                'currency' => $panel->balance_currency,
                'checkedAt' => $panel->last_checked_at?->toIso8601String(),
                'status' => $panel->status,
            ])
            ->all();
    }

    public function recentOrders(int $limit = 8): array
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BotOrder $order) => [
                'id' => $order->id,
                'service' => $order->service_name,
                'customer' => $order->customer_phone,
                'quantity' => $order->quantity,
                'amount' => $order->amount !== null ? (float) $order->amount : null,
                'status' => $this->foldStatus($order),
                'rawStatus' => $order->status ?? $order->payment_status,
                'at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function recentTickets(int $limit = 5): array
    {
        return Ticket::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'customer' => $ticket->customer_identifier,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'category' => $ticket->category,
                'at' => $ticket->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function openTicketCount(): int
    {
        return Ticket::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', ['open', 'pending'])
            ->count();
    }

    // ---- internals -------------------------------------------------------

    /**
     * Money customers actually paid: successful top-ups and direct order
     * payments. Wallet spend is deliberately excluded — that money was already
     * counted when it was deposited, and counting both would double it.
     */
    private function revenueBetween(Carbon $from, Carbon $to): float
    {
        return round((float) BotPayment::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('status', 'success')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount'), 2);
    }

    private function ordersBetween(Carbon $from, Carbon $to): int
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    private function customersBetween(Carbon $from, Carbon $to): int
    {
        return BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /** Customer money the reseller is holding but has not earned yet. */
    private function walletsHeld(): float
    {
        return round((float) BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->sum('balance'), 2);
    }

    private function botState(int $numbers, ?Carbon $lastReplyAt): string
    {
        if ($numbers === 0) {
            return 'not_connected';
        }

        if ($lastReplyAt === null) {
            return 'never_replied';
        }

        return $lastReplyAt->greaterThan(Carbon::now()->subDay()) ? 'online' : 'idle';
    }

    /**
     * Collapse a panel's status string into one of three states.
     *
     * Unpaid orders count as failed rather than pending: the customer never
     * completed the purchase, so showing it as "in progress" would have a
     * reseller waiting on something that is not coming.
     */
    private function foldStatus(BotOrder $order): string
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

    /**
     * @return Collection<string, mixed>
     */
    private function dailyTotals(string $table, string $column, Carbon $start, ?callable $filter = null): Collection
    {
        $query = DB::table($table)
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $start);

        if ($filter !== null) {
            $filter($query);
        }

        return $query
            ->selectRaw($this->dateExpression().' as day, coalesce(sum('.$column.'), 0) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
    }

    /**
     * @return Collection<string, mixed>
     */
    private function dailyCounts(string $table, Carbon $start): Collection
    {
        return DB::table($table)
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $start)
            ->selectRaw($this->dateExpression().' as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
    }

    /**
     * Postgres runs the app; SQLite runs the tests. Both need the timestamp
     * truncated to a date, and they spell it differently.
     */
    private function dateExpression(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(created_at, 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', created_at)";
    }

    /** Null when there is no prior figure to compare against. */
    private function percentChange(float|int $from, float|int $to): ?float
    {
        if ((float) $from === 0.0) {
            return null;
        }

        return round((($to - $from) / $from) * 100, 1);
    }
}
