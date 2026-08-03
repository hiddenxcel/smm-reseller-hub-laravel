<?php

namespace App\Services\Orders;

use App\Models\BotOrder;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Reads the orders list: filter, search, sort, paginate.
 *
 * Built for a table that has to stay quick with tens of thousands of rows, so
 * every path here is expected to use an index. That is also why searching is
 * deliberately narrow — see `applySearch`.
 *
 * Like DashboardMetrics, the tenant is pinned explicitly rather than left to
 * the global scope. A list page is the one screen where a missing scope shows
 * another reseller's customers row by row.
 */
class OrderQuery
{
    /** Page sizes the front end may ask for. Anything else is ignored. */
    public const PAGE_SIZES = [25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 50;

    /**
     * Sortable columns, mapped to real columns. Whitelisted rather than passed
     * through: `sort` arrives from the query string, and an unchecked column
     * name in an ORDER BY is an injection point.
     */
    public const SORTS = [
        'created_at' => 'created_at',
        'amount' => 'amount',
        'quantity' => 'quantity',
        'service' => 'service_name',
        'customer' => 'customer_phone',
        'status' => 'status',
    ];

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function paginate(OrderFilters $filters): LengthAwarePaginator
    {
        return $this->build($filters)
            ->with('panel:id,name')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /**
     * Every order id the current filters match, ignoring the page.
     *
     * This backs "select all 4,213 matching" in the bulk bar. It returns ids
     * rather than models on purpose — the actions only need keys, and pulling
     * whole rows for a five-figure selection would be pointless load.
     *
     * @return list<int>
     */
    public function matchingIds(OrderFilters $filters, int $limit): array
    {
        return $this->build($filters)
            ->reorder()
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function countMatching(OrderFilters $filters): int
    {
        return $this->build($filters)->reorder()->count();
    }

    /**
     * Walk everything the filter matches, a chunk at a time, for the CSV
     * export. Chunked by id rather than by page: the export streams while rows
     * are still being written, and OFFSET paging over a moving table can skip
     * or repeat rows.
     *
     * @param  callable(BotOrder): void  $callback
     */
    public function exportChunks(OrderFilters $filters, callable $callback): void
    {
        $this->build($filters)
            ->reorder()
            ->with('panel:id,name')
            ->chunkById(500, function ($orders) use ($callback) {
                foreach ($orders as $order) {
                    $callback($order);
                }
            });
    }

    /**
     * Counts per status tab, computed against the *other* active filters.
     *
     * A tab that ignored the current search would promise rows the table then
     * would not show, so the search and date range are applied here and only
     * the status filter itself is dropped.
     *
     * @return array<string, int>
     */
    public function tabCounts(OrderFilters $filters): array
    {
        $base = $filters->withoutStatus();

        $counts = ['all' => $this->build($base)->reorder()->count()];

        foreach (array_keys(OrderStatus::GROUPS) as $group) {
            $counts[$group] = $this->build($base->withStatus($group))->reorder()->count();
        }

        return $counts;
    }

    /** Totals for the current filter, shown under the table. */
    public function summary(OrderFilters $filters): array
    {
        $row = $this->build($filters)
            ->reorder()
            ->selectRaw('count(*) as orders, coalesce(sum(amount), 0) as revenue')
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0), 2),
        ];
    }

    private function build(OrderFilters $filters): Builder
    {
        $query = BotOrder::withoutTenantScope()->where('tenant_id', $this->tenant->id);

        $this->applyStatus($query, $filters->status);
        $this->applyPaymentStatus($query, $filters->paymentStatus);
        $this->applySearch($query, $filters->search);

        if ($filters->panelId !== null) {
            $query->where('panel_id', $filters->panelId);
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', $filters->to);
        }

        $column = self::SORTS[$filters->sort] ?? 'created_at';
        $query->orderBy($column, $filters->direction);

        // Tie-break on the primary key. Without it, rows sharing a value (many
        // orders share a status, or a created_at second) can come back in a
        // different order per page and the same row appears twice.
        if ($column !== 'created_at') {
            $query->orderByDesc('id');
        } else {
            $query->orderBy('id', $filters->direction);
        }

        return $query;
    }

    /**
     * Panels each spell their statuses differently ("Completed", "COMPLETE",
     * "In progress", "Partial"), so filtering compares against the folded
     * group rather than one literal string.
     */
    private function applyStatus(Builder $query, ?string $group): void
    {
        if ($group === null) {
            return;
        }

        $patterns = OrderStatus::GROUPS[$group] ?? null;

        if ($patterns === null) {
            return;
        }

        // "Pending" is the catch-all: a status that matched no other group,
        // including one the panel has not set at all. Written as NULL OR
        // (matches none of the claimed patterns) — a NULL never satisfies a
        // NOT LIKE in SQL, so the null case has to be its own branch.
        if ($group === OrderStatus::PENDING) {
            $query->where(function (Builder $q) {
                $q->whereNull('status')
                    ->orWhere(function (Builder $unclaimed) {
                        foreach (OrderStatus::claimedPatterns() as $pattern) {
                            $unclaimed->where('status', 'not ilike', "%{$pattern}%");
                        }
                    });
            });

            return;
        }

        $query->where(function (Builder $q) use ($patterns) {
            foreach ($patterns as $pattern) {
                $q->orWhere('status', 'ilike', "%{$pattern}%");
            }
        });
    }

    private function applyPaymentStatus(Builder $query, ?string $status): void
    {
        if (in_array($status, ['pending', 'paid', 'failed'], true)) {
            $query->where('payment_status', $status);
        }
    }

    /**
     * Search is restricted to the four fields a reseller actually looks up an
     * order by: the customer's phone, the service name, the panel's order id,
     * and our own id.
     *
     * `link` is left out on purpose. It is a free-text URL column with no
     * index, and a leading-wildcard LIKE across it is a table scan on every
     * keystroke — the one thing that would make this page slow at scale.
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        // Resellers paste ids with the '#' the UI shows them with.
        $term = ltrim($term, '#');
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($term, $like) {
            $q->where('customer_phone', 'ilike', $like)
                ->orWhere('service_name', 'ilike', $like)
                ->orWhere('provider_order_id', 'ilike', $like);

            if (ctype_digit($term)) {
                $q->orWhere('id', (int) $term);
            }
        });
    }

    /** Panels the tenant has orders on, for the panel filter. */
    public function panelOptions(): array
    {
        return $this->tenant->panels()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($panel) => ['id' => $panel->id, 'name' => $panel->name])
            ->all();
    }

    /** Shape one order row for the table. */
    public static function toRow(BotOrder $order): array
    {
        return [
            'id' => $order->id,
            'providerOrderId' => $order->provider_order_id,
            'customer' => $order->customer_phone,
            'customerId' => $order->customer_id,
            'service' => $order->service_name,
            'serviceId' => $order->service_id,
            'link' => $order->link,
            'quantity' => $order->quantity,
            'amount' => $order->amount !== null ? (float) $order->amount : null,
            'charge' => $order->charge !== null ? (float) $order->charge : null,
            'paymentStatus' => $order->payment_status,
            'paidFrom' => $order->paid_from,
            'status' => OrderStatus::fold($order->status),
            'rawStatus' => $order->status,
            'refillStatus' => $order->refill_status,
            'error' => $order->order_error,
            'panel' => $order->panel?->name,
            'panelId' => $order->panel_id,
            'createdAt' => $order->created_at?->toIso8601String(),
            'updatedAt' => $order->updated_at?->toIso8601String(),
            'actions' => OrderActions::availableFor($order),
        ];
    }

    /** Parse a date-only filter value; invalid input is ignored, not fatal. */
    public static function parseDate(?string $value, bool $endOfDay = false): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }
}
