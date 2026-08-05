<?php

namespace App\Services\Admin;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Every subscription on the platform, across resellers.
 *
 * withoutTenantScope() on every query, deliberately and not defensively: the
 * console runs outside a tenant session, so the scope would be a no-op and
 * return everything anyway — but during an impersonation it would not, and a
 * list that quietly narrowed to one reseller would be wrong in a way nobody
 * would notice. Being explicit is what makes it correct in both cases.
 *
 * "Trial" is what the UI calls a sandbox subscription: the reseller is set up
 * but has not paid. It is the same row, named for what it means commercially.
 */
class SubscriptionQuery
{
    public const PAGE_SIZES = [25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 25;

    /** Sortable columns, mapped so a URL can never name an arbitrary column. */
    public const SORTS = [
        'ends_at' => 'ends_at',
        'starts_at' => 'starts_at',
        'created_at' => 'id',
    ];

    /** The states the tabs offer, which are not the raw enum. */
    public const STATES = ['active', 'trial', 'expiring', 'expired', 'cancelled'];

    public static function make(): self
    {
        return new self;
    }

    public function paginate(SubscriptionFilters $filters): LengthAwarePaginator
    {
        return $this->apply($filters)
            ->with('plan')
            ->orderBy(self::SORTS[$filters->sort], $filters->direction)
            // A stable tiebreak, so rows do not swap between pages.
            ->orderByDesc('id')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /**
     * Reseller names for the page, in one query rather than one per row.
     *
     * @param  int[]  $tenantIds
     * @return array<int, string>
     */
    public function tenantNames(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return Tenant::whereIn('id', $tenantIds)
            ->pluck('business_name', 'id')
            ->all();
    }

    public static function toRow(Subscription $subscription, ?string $tenantName = null): array
    {
        $endsAt = $subscription->ends_at;

        return [
            'id' => $subscription->id,
            'tenantId' => $subscription->tenant_id,
            'tenant' => $tenantName ?? 'Deleted reseller',
            'service' => $subscription->service_key instanceof ServiceKey
                ? $subscription->service_key->value
                : (string) $subscription->service_key,
            'plan' => $subscription->plan?->name,
            'status' => $subscription->status->value,
            'state' => self::stateOf($subscription),
            'startsAt' => $subscription->starts_at?->toIso8601String(),
            'endsAt' => $endsAt?->toIso8601String(),
            'autoRenew' => (bool) $subscription->auto_renew,
            // Negative once it has lapsed; null for an open-ended row.
            'daysLeft' => $endsAt === null
                ? null
                : (int) Carbon::today()->diffInDays($endsAt->copy()->startOfDay(), false),
        ];
    }

    /**
     * What a row actually is, which the status column alone does not say.
     *
     * An 'active' row whose ends_at is in the past is expired in every way that
     * matters — the gate in Subscription::isServiceActive() already treats it
     * so. Showing it as active here would have an admin chasing a reseller who
     * is not, in fact, still paying.
     */
    public static function stateOf(Subscription $subscription): string
    {
        $endsAt = $subscription->ends_at;
        $lapsed = $endsAt !== null && $endsAt->isPast();

        return match (true) {
            $subscription->status === SubscriptionStatus::Cancelled => 'cancelled',
            $subscription->status === SubscriptionStatus::Sandbox => 'trial',
            $subscription->status === SubscriptionStatus::Expired, $lapsed => 'expired',
            $subscription->status === SubscriptionStatus::Active
                && $endsAt !== null
                && $endsAt->lessThanOrEqualTo(Carbon::now()->addDays(7)) => 'expiring',
            $subscription->status === SubscriptionStatus::Active => 'active',
            default => 'pending',
        };
    }

    /** Counts for the state tabs, ignoring the state filter itself. */
    public function tabCounts(SubscriptionFilters $filters): array
    {
        $base = clone $filters;
        $base->state = null;

        $counts = ['all' => $this->apply($base)->count()];

        foreach (self::STATES as $state) {
            $scoped = clone $base;
            $scoped->state = $state;

            $counts[$state] = $this->apply($scoped)->count();
        }

        return $counts;
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

    private function apply(SubscriptionFilters $filters): Builder
    {
        $query = Subscription::withoutTenantScope();

        if ($filters->service !== null) {
            $query->where('service_key', $filters->service);
        }

        if ($filters->tenantId !== null) {
            $query->where('tenant_id', $filters->tenantId);
        }

        if ($filters->search !== null) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters->search).'%';

            $query->whereIn('tenant_id', Tenant::where('business_name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->select('id'));
        }

        return $this->applyState($query, $filters->state);
    }

    /**
     * The tab states, expressed against the columns.
     *
     * 'active' deliberately excludes rows that have lapsed without anyone
     * updating their status — the same reading the subscription gate uses, so
     * this list and the reseller's own access agree with each other.
     */
    private function applyState(Builder $query, ?string $state): Builder
    {
        $now = Carbon::now();

        return match ($state) {
            'active' => $query->where('status', SubscriptionStatus::Active)
                ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
            'trial' => $query->where('status', SubscriptionStatus::Sandbox),
            'expiring' => $query->where('status', SubscriptionStatus::Active)
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [$now, $now->copy()->addDays(7)]),
            'expired' => $query->where(fn (Builder $q) => $q
                ->where('status', SubscriptionStatus::Expired)
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('status', SubscriptionStatus::Active)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<=', $now))),
            'cancelled' => $query->where('status', SubscriptionStatus::Cancelled),
            default => $query,
        };
    }
}
