<?php

namespace App\Services\Admin;

use App\Enums\ServiceKey;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The business, month by month.
 *
 * Revenue means subscription_payments — what resellers paid US. Their
 * customers' payments live in bot_payments and are never counted here; that
 * money belongs to the reseller, and mixing the two would overstate the
 * platform by an order of magnitude.
 *
 * MRR is deliberately computed from live subscriptions and plan prices rather
 * than from last month's takings. A reseller who pays for twelve months up
 * front is not worth twelve months of MRR in the month they paid, and one who
 * paid nothing this month because their year is not up yet is still recurring
 * revenue.
 */
class Reports
{
    public const MONTHS = 12;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Monthly revenue, signups, and paying resellers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthly(int $months = self::MONTHS): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        $revenue = $this->monthlyTotals('subscription_payments', $start, 'amount', 'success');
        $signups = $this->monthlyCounts('tenants', $start);
        $payers = $this->monthlyPayers($start);

        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');

            $series[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'revenue' => round((float) ($revenue[$key] ?? 0), 2),
                'signups' => (int) ($signups[$key] ?? 0),
                'payingResellers' => (int) ($payers[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Recurring revenue as it stands today.
     *
     * Each live subscription contributes its plan's monthly price. A
     * subscription whose plan row was deleted or never set contributes nothing
     * rather than guessing — an unpriced subscription is a data problem, and
     * inventing a figure for it would hide that.
     */
    public function mrr(): array
    {
        $rows = DB::table('subscriptions')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.status', 'active')
            ->where(function ($q) {
                $q->whereNull('subscriptions.ends_at')
                    ->orWhere('subscriptions.ends_at', '>', now());
            })
            ->selectRaw('subscriptions.service_key, count(*) as total, coalesce(sum(plans.price_monthly), 0) as mrr')
            ->groupBy('subscriptions.service_key')
            ->get();

        $byService = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $value = round((float) $row->mrr, 2);
            $byService[$row->service_key] = [
                'subscriptions' => (int) $row->total,
                'mrr' => $value,
            ];
            $total += $value;
        }

        // Live subscriptions with no plan attached: counted separately so the
        // gap between "subscriptions" and "priced subscriptions" is visible
        // rather than silently rounded away.
        $unpriced = Subscription::withoutTenantScope()
            ->active()
            ->whereNull('plan_id')
            ->count();

        return [
            'total' => round($total, 2),
            'byService' => $byService,
            'unpriced' => $unpriced,
        ];
    }

    /**
     * Growth over the last 30 days against the 30 before it.
     */
    public function growth(): array
    {
        $now = Carbon::now();
        $windowStart = $now->copy()->subDays(29)->startOfDay();
        $priorStart = $now->copy()->subDays(59)->startOfDay();
        $priorEnd = $windowStart->copy()->subSecond();

        $revenue = $this->revenueBetween($windowStart, $now);
        $priorRevenue = $this->revenueBetween($priorStart, $priorEnd);

        $signups = Tenant::whereBetween('created_at', [$windowStart, $now])->count();
        $priorSignups = Tenant::whereBetween('created_at', [$priorStart, $priorEnd])->count();

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
        ];
    }

    /**
     * How many resellers who signed up actually paid, by cohort month.
     *
     * The funnel question this platform lives on: a signup who never converts
     * costs support time and returns nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conversion(int $months = 6): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        $signups = $this->monthlyCounts('tenants', $start);

        $converted = DB::table('tenants')
            ->where('created_at', '>=', $start)
            ->where('first_payment_done', true)
            ->selectRaw($this->monthExpression('created_at').' as month, count(*) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');

            $joined = (int) ($signups[$key] ?? 0);
            $paid = (int) ($converted[$key] ?? 0);

            $rows[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'signups' => $joined,
                'paid' => $paid,
                'rate' => $joined > 0 ? round(($paid / $joined) * 100, 1) : null,
            ];
        }

        return $rows;
    }

    /** Revenue split by the gateway it came through. */
    public function byGateway(): array
    {
        return SubscriptionPayment::withoutTenantScope()
            ->where('status', 'success')
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

    /** Lifetime revenue per reseller, biggest first. */
    public function topResellers(int $limit = 10): array
    {
        $rows = SubscriptionPayment::withoutTenantScope()
            ->where('status', 'success')
            ->selectRaw('tenant_id, count(*) as payments, coalesce(sum(amount), 0) as revenue')
            ->groupBy('tenant_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $names = Tenant::whereIn('id', $rows->pluck('tenant_id'))
            ->pluck('business_name', 'id');

        return $rows
            ->map(fn ($row) => [
                'tenantId' => (int) $row->tenant_id,
                // `name`, matching PlatformMetrics::topResellers() — the two feed
                // the same component, and two spellings would need two types.
                'name' => $names[$row->tenant_id] ?? 'Deleted reseller',
                'payments' => (int) $row->payments,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /** Which services carry the business. */
    public function serviceMix(): array
    {
        $active = Subscription::withoutTenantScope()
            ->active()
            ->selectRaw('service_key, count(*) as total')
            ->groupBy('service_key')
            ->pluck('total', 'service_key');

        return collect(ServiceKey::cases())
            ->map(fn (ServiceKey $key) => [
                'service' => $key->value,
                'active' => (int) ($active[$key->value] ?? 0),
            ])
            ->all();
    }

    // ---- internals -------------------------------------------------------

    private function revenueBetween(Carbon $from, Carbon $to): float
    {
        return round((float) SubscriptionPayment::withoutTenantScope()
            ->where('status', 'success')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount'), 2);
    }

    private function monthlyTotals(string $table, Carbon $start, string $column, ?string $status = null)
    {
        $query = DB::table($table)->where('created_at', '>=', $start);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query
            ->selectRaw($this->monthExpression('created_at').' as month, coalesce(sum('.$column.'), 0) as total')
            ->groupBy('month')
            ->pluck('total', 'month');
    }

    private function monthlyCounts(string $table, Carbon $start)
    {
        return DB::table($table)
            ->where('created_at', '>=', $start)
            ->selectRaw($this->monthExpression('created_at').' as month, count(*) as total')
            ->groupBy('month')
            ->pluck('total', 'month');
    }

    /** Distinct resellers who paid something in each month. */
    private function monthlyPayers(Carbon $start)
    {
        return DB::table('subscription_payments')
            ->where('status', 'success')
            ->where('created_at', '>=', $start)
            ->selectRaw($this->monthExpression('created_at').' as month, count(distinct tenant_id) as total')
            ->groupBy('month')
            ->pluck('total', 'month');
    }

    /** Postgres runs the app; SQLite runs the tests. */
    private function monthExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "to_char({$column}, 'YYYY-MM')"
            : "strftime('%Y-%m', {$column})";
    }

    private function percentChange(float|int $from, float|int $to): ?float
    {
        if ((float) $from === 0.0) {
            return null;
        }

        return round((($to - $from) / $from) * 100, 1);
    }
}
