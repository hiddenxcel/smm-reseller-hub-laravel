<?php

namespace App\Services\Admin;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The reseller list behind the admin console.
 *
 * Tenant itself carries no tenant scope — it IS the tenant — so this reads the
 * table directly. Everything it joins to does carry the scope, so those queries
 * go through withoutTenantScope() explicitly: during an impersonation a tenant
 * session is live, and a subscription count that quietly narrowed to that one
 * reseller would put the wrong number against every row.
 */
class TenantQuery
{
    public const PAGE_SIZES = [25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 25;

    /** Sortable columns, mapped so a URL can never name an arbitrary column. */
    public const SORTS = [
        'created_at' => 'created_at',
        'name' => 'business_name',
        'status' => 'status',
        'credit' => 'referral_credit',
    ];

    public const BILLING_STATES = ['paying', 'trial', 'never_paid'];

    public static function make(): self
    {
        return new self;
    }

    /** @return string[] */
    public static function serviceKeys(): array
    {
        return array_map(fn (ServiceKey $key) => $key->value, ServiceKey::cases());
    }

    public function paginate(TenantFilters $filters): LengthAwarePaginator
    {
        return $this->apply($filters)
            ->orderBy(self::SORTS[$filters->sort], $filters->direction)
            // A stable tiebreak: without it, two resellers who signed up in the
            // same second can swap places between pages and one is never seen.
            ->orderByDesc('id')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /** Ids matching the current filters, for a select-all that spans pages. */
    public function matchingIds(TenantFilters $filters, int $limit = 1000): array
    {
        return $this->apply($filters)->limit($limit)->pluck('id')->all();
    }

    /**
     * One row of the list.
     *
     * Subscriptions and totals are passed in rather than read per row: the list
     * shows 25 resellers, and a query each would be 75 queries a page.
     */
    public static function toRow(Tenant $tenant, array $services = [], array $totals = []): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->business_name,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'status' => $tenant->status,
            'lang' => $tenant->lang,
            'credit' => (float) $tenant->referral_credit,
            'hasPaid' => (bool) $tenant->first_payment_done,
            'services' => $services,
            'revenue' => round((float) ($totals['revenue'] ?? 0), 2),
            'orders' => (int) ($totals['orders'] ?? 0),
            'joinedAt' => $tenant->created_at?->toIso8601String(),
        ];
    }

    /**
     * serviceKey => state for each tenant on the page, in one query.
     *
     * @param  int[]  $tenantIds
     * @return array<int, array<string, string>>
     */
    public function servicesFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $rows = Subscription::withoutTenantScope()
            ->whereIn('tenant_id', $tenantIds)
            ->get(['tenant_id', 'service_key', 'status', 'ends_at']);

        $map = [];

        foreach ($rows as $row) {
            $key = $row->service_key instanceof ServiceKey
                ? $row->service_key->value
                : (string) $row->service_key;

            $state = match (true) {
                $this->isLive($row) => 'active',
                $row->status === SubscriptionStatus::Sandbox => 'sandbox',
                default => 'expired',
            };

            // A reseller can hold several rows for one service over time; the
            // strongest state is the one that describes them today.
            $existing = $map[$row->tenant_id][$key] ?? null;

            if ($existing === null || $this->rank($state) > $this->rank($existing)) {
                $map[$row->tenant_id][$key] = $state;
            }
        }

        return $map;
    }

    /**
     * Lifetime platform revenue and order count per tenant, in two queries.
     *
     * @param  int[]  $tenantIds
     * @return array<int, array{revenue: float, orders: int}>
     */
    public function totalsFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $revenue = DB::table('subscription_payments')
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'success')
            ->selectRaw('tenant_id, coalesce(sum(amount), 0) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        $orders = DB::table('bot_orders')
            ->whereIn('tenant_id', $tenantIds)
            ->selectRaw('tenant_id, count(*) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        $totals = [];

        foreach ($tenantIds as $id) {
            $totals[$id] = [
                'revenue' => round((float) ($revenue[$id] ?? 0), 2),
                'orders' => (int) ($orders[$id] ?? 0),
            ];
        }

        return $totals;
    }

    /** Counts for the status tabs, ignoring the status filter itself. */
    public function tabCounts(TenantFilters $filters): array
    {
        $base = clone $filters;
        $base->status = null;

        return [
            'all' => $this->apply($base)->count(),
            'active' => $this->apply($base)->where('status', 'active')->count(),
            'suspended' => $this->apply($base)->where('status', 'suspended')->count(),
        ];
    }

    public static function parseDate(string $value, bool $endOfDay = false): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    // ---- internals -------------------------------------------------------

    private function apply(TenantFilters $filters): Builder
    {
        $query = Tenant::query();

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->search !== null) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters->search).'%';

            $query->where(function (Builder $q) use ($term) {
                $q->where('business_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('referral_code', 'like', $term);
            });
        }

        if ($filters->service !== null) {
            $query->whereIn('id', Subscription::withoutTenantScope()
                ->forService($filters->service)
                ->active()
                ->select('tenant_id'));
        }

        $query = $this->applyBilling($query, $filters->billing);

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', $filters->to);
        }

        return $query;
    }

    /**
     * "Trial" is a reseller running on sandbox subscriptions who has never
     * paid — the state the funnel cares about, and one no column records.
     */
    private function applyBilling(Builder $query, ?string $billing): Builder
    {
        return match ($billing) {
            'paying' => $query->where('first_payment_done', true),
            'never_paid' => $query->where('first_payment_done', false),
            'trial' => $query->where('first_payment_done', false)
                ->whereIn('id', Subscription::withoutTenantScope()
                    ->where('status', SubscriptionStatus::Sandbox)
                    ->select('tenant_id')),
            default => $query,
        };
    }

    private function isLive(Subscription $subscription): bool
    {
        return $subscription->status === SubscriptionStatus::Active
            && ($subscription->ends_at === null || $subscription->ends_at->isFuture());
    }

    private function rank(string $state): int
    {
        return match ($state) {
            'active' => 3,
            'sandbox' => 2,
            default => 1,
        };
    }
}
