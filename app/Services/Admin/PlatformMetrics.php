<?php

namespace App\Services\Admin;

use App\Enums\SubscriptionStatus;
use App\Models\BotOrder;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The whole platform at a glance, across every reseller.
 *
 * The mirror image of DashboardMetrics: that class is careful to constrain each
 * aggregate to one tenant, and this one is careful not to. Every query here
 * calls withoutTenantScope() explicitly rather than relying on the scope being
 * a no-op outside a tenant session — during an impersonation a tenant session
 * does exist, and a figure that silently narrowed to that one reseller would be
 * wrong in a way nobody would notice.
 *
 * Revenue means what resellers paid US (subscription_payments), never what
 * their customers paid them (bot_payments) — that money is not ours and
 * counting it would overstate the business by an order of magnitude.
 */
class PlatformMetrics
{
    public const TREND_DAYS = 30;

    public static function make(): self
    {
        return new self;
    }

    /**
     * The headline row, each figure against the previous equal-length period.
     */
    public function kpis(): array
    {
        $now = Carbon::now();
        $windowStart = $now->copy()->subDays(29)->startOfDay();
        $priorStart = $now->copy()->subDays(59)->startOfDay();
        $priorEnd = $windowStart->copy()->subSecond();

        $revenue = $this->revenueBetween($windowStart, $now);
        $priorRevenue = $this->revenueBetween($priorStart, $priorEnd);

        $signups = $this->signupsBetween($windowStart, $now);
        $priorSignups = $this->signupsBetween($priorStart, $priorEnd);

        return [
            'revenue' => [
                'value' => $revenue,
                'previous' => $priorRevenue,
                'delta' => $this->percentChange($priorRevenue, $revenue),
            ],
            'signups' => [
                'value' => $signups,
                'previous' => $priorSignups,
                'delta' => $this->percentChange($priorSignups, $signups),
            ],
            'tenants' => [
                'total' => Tenant::count(),
                'active' => Tenant::where('status', 'active')->count(),
                'suspended' => Tenant::where('status', 'suspended')->count(),
                // A reseller who has never paid is a very different figure from
                // one who has stopped: the first is a funnel problem.
                'paying' => Tenant::where('first_payment_done', true)->count(),
            ],
            'subscriptions' => [
                'active' => Subscription::withoutTenantScope()->active()->count(),
                'sandbox' => Subscription::withoutTenantScope()
                    ->where('status', SubscriptionStatus::Sandbox)
                    ->count(),
                'expiringSoon' => $this->expiringSoon(),
            ],
            'ordersToday' => BotOrder::withoutTenantScope()
                ->where('created_at', '>=', Carbon::today())
                ->count(),
        ];
    }

    /**
     * Daily platform revenue and reseller signups.
     *
     * Empty days are emitted at zero so the line does not compress time.
     *
     * @return array<int, array{date: string, revenue: float, signups: int}>
     */
    public function trend(): array
    {
        $start = Carbon::today()->subDays(self::TREND_DAYS - 1);

        $revenueByDay = $this->dailyRevenue($start);
        $signupsByDay = $this->dailyCounts('tenants', $start);

        $series = [];

        for ($day = 0; $day < self::TREND_DAYS; $day++) {
            $date = $start->copy()->addDays($day)->toDateString();

            $series[] = [
                'date' => $date,
                'revenue' => round((float) ($revenueByDay[$date] ?? 0), 2),
                'signups' => (int) ($signupsByDay[$date] ?? 0),
            ];
        }

        return $series;
    }

    /** Which of the a-la-carte services are actually selling. */
    public function serviceMix(): array
    {
        return Subscription::withoutTenantScope()
            ->active()
            ->selectRaw('service_key, count(*) as total')
            ->groupBy('service_key')
            ->pluck('total', 'service_key')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** Resellers by what they have paid the platform, all time. */
    public function topResellers(int $limit = 5): array
    {
        return SubscriptionPayment::withoutTenantScope()
            ->where('status', 'success')
            ->selectRaw('tenant_id, count(*) as payments, coalesce(sum(amount), 0) as revenue')
            ->groupBy('tenant_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'tenantId' => (int) $row->tenant_id,
                'name' => Tenant::find($row->tenant_id)?->business_name ?? 'Deleted reseller',
                'payments' => (int) $row->payments,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    public function recentSignups(int $limit = 8): array
    {
        return Tenant::orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->business_name,
                'email' => $tenant->email,
                'status' => $tenant->status,
                'paid' => (bool) $tenant->first_payment_done,
                'at' => $tenant->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * What needs someone's attention right now.
     *
     * Deliberately only conditions a human can act on today. A count that is
     * always non-zero stops being read, so slow-moving totals belong in the KPI
     * row, not here.
     */
    public function alerts(): array
    {
        $failedPayments = SubscriptionPayment::withoutTenantScope()
            ->where('status', 'failed')
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        return [
            'failedPayments' => $failedPayments,
            'openTickets' => Ticket::withoutTenantScope()
                ->whereIn('status', ['open', 'pending'])
                ->count(),
            'expiringSoon' => $this->expiringSoon(),
            'suspendedTenants' => Tenant::where('status', 'suspended')->count(),
            // Numbers attached but never used are stalled onboarding: the
            // reseller connected WhatsApp and then stopped.
            'silentNumbers' => $this->silentNumbers(),
        ];
    }

    /** Bots that have a number but have not replied in 48 hours. */
    private function silentNumbers(): int
    {
        $recentlyActive = DB::table('bot_messages')
            ->where('direction', 'out')
            ->where('created_at', '>=', Carbon::now()->subDays(2))
            ->distinct()
            ->pluck('tenant_id');

        return TenantWhatsApp::withoutTenantScope()
            ->whereNotIn('tenant_id', $recentlyActive)
            ->count();
    }

    private function expiringSoon(): int
    {
        return Subscription::withoutTenantScope()
            ->active()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now()->addDays(7))
            ->count();
    }

    private function revenueBetween(Carbon $from, Carbon $to): float
    {
        return round((float) SubscriptionPayment::withoutTenantScope()
            ->where('status', 'success')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount'), 2);
    }

    private function signupsBetween(Carbon $from, Carbon $to): int
    {
        return Tenant::whereBetween('created_at', [$from, $to])->count();
    }

    /**
     * @return Collection<string, mixed>
     */
    private function dailyRevenue(Carbon $start): Collection
    {
        return DB::table('subscription_payments')
            ->where('status', 'success')
            ->where('created_at', '>=', $start)
            ->selectRaw($this->dateExpression().' as day, coalesce(sum(amount), 0) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
    }

    /**
     * @return Collection<string, mixed>
     */
    private function dailyCounts(string $table, Carbon $start): Collection
    {
        return DB::table($table)
            ->where('created_at', '>=', $start)
            ->selectRaw($this->dateExpression().' as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
    }

    /** Postgres runs the app; SQLite runs the tests. */
    private function dateExpression(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(created_at, 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', created_at)";
    }

    private function percentChange(float|int $from, float|int $to): ?float
    {
        if ((float) $from === 0.0) {
            return null;
        }

        return round((($to - $from) / $from) * 100, 1);
    }
}
