<?php

namespace App\Services\Customers;

use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the customers list: KPIs, filter, search, sort, paginate.
 *
 * Built to stay quick on a reseller with tens of thousands of customers, so
 * per-row work is kept out of the loop — order counts and totals come back as
 * subqueries on the page's rows rather than a query each, and "which bots has
 * this person used" is one grouped query for the whole page instead of one per
 * customer.
 *
 * The tenant is pinned explicitly rather than left to the global scope. On a
 * list screen a missing scope shows another reseller's customers row by row,
 * phone numbers and all.
 */
class CustomerQuery
{
    public const PAGE_SIZES = [25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 50;

    /** Wallet balance bands offered in the filter. */
    public const WALLET_BANDS = ['empty', 'low', 'funded', 'high'];

    /**
     * Sortable columns, whitelisted — `sort` comes from the query string and
     * an unchecked column name in an ORDER BY is an injection point.
     */
    public const SORTS = [
        'last_seen_at' => 'last_seen_at',
        'created_at' => 'created_at',
        'name' => 'name',
        'phone' => 'phone',
        'balance' => 'balance',
        'total_spent' => 'total_spent',
        'orders' => 'orders_count',
    ];

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function paginate(CustomerFilters $filters): LengthAwarePaginator
    {
        return $this->build($filters)
            ->withCount('orders')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /**
     * @return list<int>
     */
    public function matchingIds(CustomerFilters $filters, int $limit): array
    {
        return $this->build($filters)
            ->reorder()
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function countMatching(CustomerFilters $filters): int
    {
        return $this->build($filters)->reorder()->count();
    }

    /**
     * The six KPI tiles.
     *
     * Deliberately computed over the tenant's whole book rather than the
     * current filter: these are the shop's headline numbers, and a "Total
     * customers" that moved every time someone typed in the search box would
     * be worse than useless.
     */
    public function kpis(): array
    {
        $base = fn () => BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id);

        $totals = $base()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(balance), 0) as wallets')
            ->selectRaw('coalesce(sum(total_spent), 0) as lifetime')
            ->selectRaw('count(*) filter (where created_at >= ?) as new_today', [Carbon::today()])
            ->selectRaw('count(*) filter (where blocked_at is null and last_seen_at > ?) as active', [
                Carbon::now()->subDays(CustomerSegment::ACTIVE_DAYS),
            ])
            ->first();

        // VIP has an OR across a column and an order count, so it does not fold
        // into the aggregate above.
        $vip = $base()
            ->whereNull('blocked_at')
            ->where(fn (Builder $q) => $q
                ->where('total_spent', '>=', CustomerSegment::VIP_SPEND)
                ->orHas('orders', '>=', CustomerSegment::VIP_ORDERS))
            ->count();

        // Yesterday's equivalents, so each tile can show a direction rather
        // than a number with nothing to compare it to.
        $priorTotal = $base()->where('created_at', '<', Carbon::today())->count();
        $newYesterday = $base()
            ->whereBetween('created_at', [Carbon::yesterday(), Carbon::today()])
            ->count();

        return [
            'total' => [
                'value' => (int) ($totals->total ?? 0),
                'delta' => $this->percentChange($priorTotal, (int) ($totals->total ?? 0)),
            ],
            'newToday' => [
                'value' => (int) ($totals->new_today ?? 0),
                'delta' => $this->percentChange($newYesterday, (int) ($totals->new_today ?? 0)),
            ],
            'vip' => ['value' => $vip, 'delta' => null],
            'active' => ['value' => (int) ($totals->active ?? 0), 'delta' => null],
            'wallets' => ['value' => round((float) ($totals->wallets ?? 0), 2), 'delta' => null],
            'lifetime' => ['value' => round((float) ($totals->lifetime ?? 0), 2), 'delta' => null],
        ];
    }

    /**
     * Counts per segment tab, computed against the other active filters — a
     * tab that ignored the current search would promise rows the table then
     * would not show.
     *
     * @return array<string, int>
     */
    public function tabCounts(CustomerFilters $filters): array
    {
        $base = $filters->withoutSegment();

        $counts = ['all' => $this->build($base)->reorder()->count()];

        foreach ([
            CustomerSegment::VIP,
            CustomerSegment::ACTIVE,
            CustomerSegment::NEW,
            CustomerSegment::BLOCKED,
        ] as $segment) {
            $counts[$segment] = $this->build($base->withSegment($segment))->reorder()->count();
        }

        return $counts;
    }

    /** Countries and tags actually in use, for the filter dropdowns. */
    public function filterOptions(): array
    {
        $countries = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->all();

        // Tags live in a JSON column, so they are flattened in PHP. Bounded by
        // the tenant's own customer count, and only the ones actually set.
        $tags = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return ['countries' => $countries, 'tags' => $tags];
    }

    private function build(CustomerFilters $filters): Builder
    {
        $query = BotCustomer::withoutTenantScope()->where('tenant_id', $this->tenant->id);

        if ($filters->segment !== null) {
            CustomerSegment::scope($query, $filters->segment);
        }

        $this->applySearch($query, $filters->search);
        $this->applyBot($query, $filters->bot);
        $this->applyWalletBand($query, $filters->walletBand);

        if ($filters->country !== null) {
            $query->where('country', $filters->country);
        }

        if ($filters->tag !== null) {
            // Postgres JSONB containment; the value is bound, not interpolated.
            $query->whereJsonContains('tags', $filters->tag);
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', $filters->to);
        }

        $this->applySort($query, $filters);

        return $query;
    }

    private function applySort(Builder $query, CustomerFilters $filters): void
    {
        $column = self::SORTS[$filters->sort] ?? 'last_seen_at';

        if ($column === 'orders_count') {
            // Sorting by a withCount alias needs the subquery in the ORDER BY
            // itself — the alias is not available to it.
            $query->orderBy(
                BotCustomer::withoutTenantScope()
                    ->from('bot_orders')
                    ->whereColumn('bot_orders.customer_id', 'bot_customers.id')
                    ->selectRaw('count(*)'),
                $filters->direction,
            );
        } else {
            // Customers who have never messaged sort last either way: a null
            // last_seen_at means "unknown", and unknowns at the top of a
            // recency sort push down every row the reseller wanted to see.
            $query->orderByRaw(
                $filters->direction === 'asc'
                    ? "{$column} asc nulls last"
                    : "{$column} desc nulls last"
            );
        }

        // Tie-break, so rows sharing a value cannot appear on two pages.
        $query->orderByDesc('id');
    }

    /**
     * Search covers the fields a reseller looks a customer up by: phone, name,
     * email, referral code, and the customer's own id.
     *
     * `notes` is left out — it is free text with no index, and a leading
     * wildcard across it is a table scan on every keystroke.
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $term = ltrim($term, '#');
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        // People search phone numbers however they wrote them down: +255…,
        // 255…, with spaces. Comparing on digits alone finds them all.
        $digits = preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $q) use ($term, $like, $digits) {
            $q->where('phone', 'ilike', $like)
                ->orWhere('name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('referral_code', 'ilike', $like);

            if ($digits !== '' && strlen($digits) >= 3) {
                $q->orWhereRaw("regexp_replace(phone, '\\D', '', 'g') like ?", ["%{$digits}%"]);
            }

            if (ctype_digit($term)) {
                $q->orWhere('id', (int) $term);
            }
        });
    }

    /**
     * Which bot(s) this customer has talked to, read from the message log —
     * there is no column for it, and there should not be: the log is the fact.
     */
    private function applyBot(Builder $query, ?string $bot): void
    {
        if ($bot === null) {
            return;
        }

        $messagesFrom = fn (string $type) => BotMessage::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('bot_type', $type)
            ->whereColumn('bot_messages.customer_phone', 'bot_customers.phone');

        match ($bot) {
            'order' => $query->whereExists($messagesFrom('order')),
            'support' => $query->whereExists($messagesFrom('support')),
            'both' => $query
                ->whereExists($messagesFrom('order'))
                ->whereExists($messagesFrom('support')),
            default => null,
        };
    }

    private function applyWalletBand(Builder $query, ?string $band): void
    {
        match ($band) {
            'empty' => $query->where('balance', '<=', 0),
            'low' => $query->where('balance', '>', 0)->where('balance', '<', 5),
            'funded' => $query->where('balance', '>=', 5)->where('balance', '<', 50),
            'high' => $query->where('balance', '>=', 50),
            default => null,
        };
    }

    /**
     * Which bots each phone on this page has used — one grouped query for the
     * whole page rather than one per row.
     *
     * @param  list<string>  $phones
     * @return array<string, list<string>>
     */
    public function botUsageFor(array $phones): array
    {
        if ($phones === []) {
            return [];
        }

        return BotMessage::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('customer_phone', $phones)
            ->whereNotNull('bot_type')
            ->select('customer_phone', 'bot_type')
            ->distinct()
            ->get()
            ->groupBy('customer_phone')
            ->map(fn ($rows) => $rows->pluck('bot_type')->unique()->values()->all())
            ->all();
    }

    /**
     * Shape one customer row for the table.
     *
     * @param  list<string>  $bots
     */
    public static function toRow(BotCustomer $customer, array $bots = []): array
    {
        $orderCount = (int) ($customer->orders_count ?? 0);

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'country' => $customer->country,
            'lang' => $customer->lang,
            'tags' => $customer->tags ?? [],
            'orders' => $orderCount,
            'spent' => (float) $customer->total_spent,
            'balance' => (float) $customer->balance,
            'referralCode' => $customer->referral_code,
            'bots' => $bots,
            'segments' => CustomerSegment::for($customer, $orderCount),
            'blocked' => $customer->blocked_at !== null,
            'lastSeenAt' => $customer->last_seen_at?->toIso8601String(),
            'createdAt' => $customer->created_at?->toIso8601String(),
        ];
    }

    /**
     * Walk everything the filter matches for the CSV export, chunked by id —
     * the export streams while rows are still being written, and OFFSET paging
     * over a moving table can skip or repeat rows.
     *
     * @param  callable(BotCustomer): void  $callback
     */
    public function exportChunks(CustomerFilters $filters, callable $callback): void
    {
        $this->build($filters)
            ->reorder()
            ->withCount('orders')
            ->chunkById(500, function ($customers) use ($callback) {
                foreach ($customers as $customer) {
                    $callback($customer);
                }
            });
    }

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

    private function percentChange(float $previous, float $current): ?float
    {
        if ($previous <= 0.0) {
            return null; // no baseline — a percentage would invent a story
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
