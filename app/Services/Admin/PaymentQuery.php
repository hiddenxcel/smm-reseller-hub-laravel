<?php

namespace App\Services\Admin;

use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Every payment a reseller has made to the platform.
 *
 * Deliberately only subscription_payments — never bot_payments. Those are a
 * reseller's customers paying THEM; that money is not ours, and mixing the two
 * would overstate the business by an order of magnitude. The two tables have
 * similar shapes and opposite meanings, which is exactly why this class names
 * the one it reads in its first line.
 */
class PaymentQuery
{
    public const PAGE_SIZES = [25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 25;

    public const SORTS = [
        'created_at' => 'id',
        'amount' => 'amount',
    ];

    public const STATUSES = ['pending', 'success', 'failed'];

    public static function make(): self
    {
        return new self;
    }

    public function paginate(PaymentFilters $filters): LengthAwarePaginator
    {
        return $this->apply($filters)
            ->orderBy(self::SORTS[$filters->sort], $filters->direction)
            ->orderByDesc('id')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /**
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

    /**
     * One row of the list.
     *
     * `raw_response` is not included: it can be kilobytes of gateway JSON per
     * row, and it is only wanted when someone opens a single payment to work
     * out what went wrong.
     */
    public static function toRow(SubscriptionPayment $payment, ?string $tenantName = null): array
    {
        return [
            'id' => $payment->id,
            'tenantId' => $payment->tenant_id,
            'tenant' => $tenantName ?? 'Deleted reseller',
            'gateway' => $payment->gateway,
            'reference' => $payment->transaction_ref,
            'amount' => (float) $payment->amount,
            'creditApplied' => (float) $payment->credit_applied,
            'currency' => $payment->currency,
            'months' => $payment->months,
            'status' => $payment->status,
            'items' => $payment->items,
            'at' => $payment->created_at?->toIso8601String(),
            // Stuck: taken but never confirmed. The state a human has to settle,
            // and the reason this screen has a confirm button at all.
            'stale' => $payment->status === 'pending'
                && $payment->created_at !== null
                && $payment->created_at->lessThan(Carbon::now()->subHours(2)),
        ];
    }

    /** One payment in full, including what the gateway actually sent. */
    public static function toDetail(SubscriptionPayment $payment, ?string $tenantName = null): array
    {
        return [
            ...self::toRow($payment, $tenantName),
            'rawResponse' => $payment->raw_response,
            'binanceOrderId' => $payment->binance_order_id,
            'planId' => $payment->plan_id,
            'subscriptionId' => $payment->subscription_id,
        ];
    }

    public function tabCounts(PaymentFilters $filters): array
    {
        $base = clone $filters;
        $base->status = null;

        $counts = ['all' => $this->apply($base)->count()];

        foreach (self::STATUSES as $status) {
            $scoped = clone $base;
            $scoped->status = $status;

            $counts[$status] = $this->apply($scoped)->count();
        }

        return $counts;
    }

    /** Headline figures for the payments screen, over the current filters. */
    public function totals(PaymentFilters $filters): array
    {
        $succeeded = clone $filters;
        $succeeded->status = 'success';

        $pending = clone $filters;
        $pending->status = 'pending';

        return [
            'collected' => round((float) $this->apply($succeeded)->sum('amount'), 2),
            'creditApplied' => round((float) $this->apply($succeeded)->sum('credit_applied'), 2),
            'pendingValue' => round((float) $this->apply($pending)->sum('amount'), 2),
        ];
    }

    /** Which gateways have actually been used, for the filter dropdown. */
    public function gatewaysSeen(): array
    {
        return SubscriptionPayment::withoutTenantScope()
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway')
            ->all();
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

    private function apply(PaymentFilters $filters): Builder
    {
        $query = SubscriptionPayment::withoutTenantScope();

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->gateway !== null) {
            $query->where('gateway', $filters->gateway);
        }

        if ($filters->tenantId !== null) {
            $query->where('tenant_id', $filters->tenantId);
        }

        if ($filters->search !== null) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters->search).'%';

            // A reference is what someone pastes in from a gateway dashboard,
            // so it is matched before anything else.
            $query->where(function (Builder $q) use ($term) {
                $q->where('transaction_ref', 'like', $term)
                    ->orWhere('binance_order_id', 'like', $term)
                    ->orWhereIn('tenant_id', Tenant::where('business_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->select('id'));
            });
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', $filters->to);
        }

        return $query;
    }
}
