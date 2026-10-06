<?php

namespace App\Services\Catalogue;

use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads the services list: KPIs, filter, search, sort, paginate.
 *
 * A reseller can carry thousands of services, so this avoids per-row work
 * entirely — order counts for a page come back as one grouped query, not one
 * per service, and margin is computed in SQL where it is filtered on.
 *
 * The tenant is pinned explicitly rather than left to the global scope: on a
 * list screen a missing scope shows another reseller's costs and margins,
 * which is their commercial position.
 */
class ServiceQuery
{
    public const PAGE_SIZES = [25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 50;

    /** Margin bands offered in the filter. */
    public const MARGIN_BANDS = ['loss', 'thin', 'healthy', 'high', 'unknown'];

    public const SORTS = [
        'name' => 'name',
        'platform' => 'platform',
        'cost_price' => 'cost_price',
        'my_price' => 'my_price',
        'profit' => 'profit',
        'margin' => 'margin',
        'updated_at' => 'updated_at',
    ];

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function paginate(ServiceFilters $filters): LengthAwarePaginator
    {
        return $this->build($filters)
            ->with('panel:id,name')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /**
     * @return list<int>
     */
    public function matchingIds(ServiceFilters $filters, int $limit): array
    {
        return $this->build($filters)
            ->reorder()
            ->limit($limit)
            ->pluck('bot_services.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function countMatching(ServiceFilters $filters): int
    {
        return $this->build($filters)->reorder()->count();
    }

    /**
     * The six KPI tiles, over the whole catalogue rather than the filter — a
     * "Total services" that moved with the search box would mean nothing.
     */
    public function kpis(): array
    {
        $row = BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where status = ?) as active', [BotService::ACTIVE])
            ->selectRaw('count(*) filter (where status = ?) as hidden', [BotService::HIDDEN])
            ->selectRaw('count(*) filter (where status = ?) as paused', [BotService::PAUSED])
            ->selectRaw('count(distinct platform) as platforms')
            ->selectRaw('max(last_synced_at) as last_synced')
            // Averaged over services that actually have a cost: including the
            // ones with no cost as zero would drag the figure towards a margin
            // nobody is earning.
            ->selectRaw('avg((my_price - cost_price)) filter (where cost_price is not null) as avg_profit')
            ->selectRaw(
                'avg(((my_price - cost_price) / nullif(my_price, 0)) * 100)'
                .' filter (where cost_price is not null and my_price > 0) as avg_margin'
            )
            ->selectRaw(
                'count(*) filter (where cost_price is not null and my_price < cost_price) as underwater'
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'hidden' => (int) ($row->hidden ?? 0),
            'paused' => (int) ($row->paused ?? 0),
            'platforms' => (int) ($row->platforms ?? 0),
            'avgProfit' => $row->avg_profit === null ? null : round((float) $row->avg_profit, 4),
            'avgMargin' => $row->avg_margin === null ? null : round((float) $row->avg_margin, 1),
            // Not asked for, but it is the one number that costs a reseller
            // money while they are not looking.
            'underwater' => (int) ($row->underwater ?? 0),
            'lastSyncedAt' => $row->last_synced === null
                ? null
                : Carbon::parse($row->last_synced)->toIso8601String(),
        ];
    }

    /**
     * Platforms with their counts, for the sidebar. Computed against the other
     * filters so the counts match what clicking one would show.
     *
     * @return list<array{platform: string, services: int, active: int}>
     */
    public function platformCounts(ServiceFilters $filters): array
    {
        return $this->build($filters->withoutPlatform())
            ->reorder()
            ->select('platform')
            ->selectRaw('count(*) as services')
            ->selectRaw('count(*) filter (where status = ?) as active', [BotService::ACTIVE])
            ->groupBy('platform')
            ->orderByDesc('services')
            ->get()
            ->map(fn ($row) => [
                'platform' => (string) $row->platform,
                'services' => (int) $row->services,
                'active' => (int) $row->active,
            ])
            ->all();
    }

    /** Categories within the selected platform, for the second-level filter. */
    public function categoryCounts(ServiceFilters $filters): array
    {
        $clone = clone $filters;
        $clone->category = null;

        return $this->build($clone)
            ->reorder()
            ->whereNotNull('category')
            ->select('category')
            ->selectRaw('count(*) as services')
            ->groupBy('category')
            ->orderByDesc('services')
            ->get()
            ->map(fn ($row) => [
                'category' => (string) $row->category,
                'services' => (int) $row->services,
            ])
            ->all();
    }

    /** @return array<string, int> */
    public function tabCounts(ServiceFilters $filters): array
    {
        $base = $filters->withoutStatus();

        $counts = ['all' => $this->build($base)->reorder()->count()];

        foreach (BotService::STATUSES as $status) {
            $counts[$status] = $this->build($base->withStatus($status))->reorder()->count();
        }

        return $counts;
    }

    /** Panels the reseller has connected, for the provider filter. */
    public function panelOptions(): array
    {
        return $this->tenant->panels()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($panel) => ['id' => $panel->id, 'name' => $panel->name])
            ->all();
    }

    private function build(ServiceFilters $filters): Builder
    {
        $query = BotService::withoutTenantScope()
            ->where('bot_services.tenant_id', $this->tenant->id)
            // Profit and margin are selected rather than computed per row so
            // they can be sorted and filtered on in the database.
            ->select('bot_services.*')
            ->selectRaw('(my_price - cost_price) as profit')
            ->selectRaw('((my_price - cost_price) / nullif(my_price, 0)) * 100 as margin');

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->platform !== null) {
            $query->where('platform', $filters->platform);
        }

        if ($filters->category !== null) {
            $query->where('category', $filters->category);
        }

        if ($filters->panelId !== null) {
            $query->where('panel_id', $filters->panelId);
        }

        if ($filters->featuredOnly) {
            $query->where('featured', true);
        }

        if ($filters->minPrice !== null) {
            $query->where('my_price', '>=', $filters->minPrice);
        }

        if ($filters->maxPrice !== null) {
            $query->where('my_price', '<=', $filters->maxPrice);
        }

        $this->applySearch($query, $filters->search);
        $this->applyMarginBand($query, $filters->margin);
        $this->applySort($query, $filters);

        return $query;
    }

    /**
     * Search covers what a reseller looks a service up by: its name, the
     * panel's id for it, the platform, the category, and our own id.
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $term = ltrim($term, '#');
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($term, $like) {
            $q->where('name', 'ilike', $like)
                ->orWhere('provider_service_id', 'ilike', $like)
                ->orWhere('platform', 'ilike', $like)
                ->orWhere('category', 'ilike', $like);

            if (ctype_digit($term)) {
                $q->orWhere('bot_services.id', (int) $term);
            }
        });
    }

    /**
     * Margin bands. `unknown` is its own band rather than being folded into
     * `loss`: a service the panel never priced is not losing money, it is
     * simply unmeasured, and treating the two alike would send a reseller
     * hunting for a problem that is not there.
     */
    private function applyMarginBand(Builder $query, ?string $band): void
    {
        match ($band) {
            'unknown' => $query->whereNull('cost_price'),
            'loss' => $query->whereNotNull('cost_price')->whereRaw('my_price < cost_price'),
            'thin' => $query->whereNotNull('cost_price')
                ->whereRaw('my_price >= cost_price')
                ->whereRaw('((my_price - cost_price) / nullif(my_price, 0)) * 100 < 10'),
            'healthy' => $query->whereNotNull('cost_price')
                ->whereRaw('((my_price - cost_price) / nullif(my_price, 0)) * 100 between 10 and 40'),
            'high' => $query->whereNotNull('cost_price')
                ->whereRaw('((my_price - cost_price) / nullif(my_price, 0)) * 100 > 40'),
            default => null,
        };
    }

    private function applySort(Builder $query, ServiceFilters $filters): void
    {
        $column = self::SORTS[$filters->sort] ?? 'name';

        // Services with no cost have no profit or margin. They sort last
        // either way — an unknown is not a small number.
        if (in_array($column, ['profit', 'margin', 'cost_price'], true)) {
            $query->orderByRaw(
                $filters->direction === 'asc'
                    ? "{$column} asc nulls last"
                    : "{$column} desc nulls last"
            );
        } else {
            $query->orderBy($column, $filters->direction);
        }

        // Tie-break, so rows sharing a value cannot appear on two pages.
        $query->orderBy('bot_services.id');
    }

    /**
     * How many orders each service on this page has taken — one grouped query
     * for the page rather than one per row.
     *
     * Orders record the PANEL's service id, not ours, so this keys on
     * (panel_id, provider_service_id). Two panels can hand out the same id.
     *
     * @param  Collection<int, BotService>  $services
     * @return array<string, int>
     */
    public function orderCountsFor($services): array
    {
        $keys = $services
            ->map(fn (BotService $service) => [
                'panel_id' => $service->panel_id,
                'provider_service_id' => $service->provider_service_id,
            ])
            ->filter(fn (array $key) => $key['provider_service_id'] !== null);

        if ($keys->isEmpty()) {
            return [];
        }

        $rows = BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('service_id', $keys->pluck('provider_service_id')->unique()->all())
            ->select('service_id', 'panel_id')
            ->selectRaw('count(*) as orders')
            ->groupBy('service_id', 'panel_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[self::orderKey($row->panel_id, (string) $row->service_id)] = (int) $row->orders;
        }

        return $counts;
    }

    public static function orderKey(?int $panelId, ?string $providerServiceId): string
    {
        return ($panelId ?? 0).':'.($providerServiceId ?? '');
    }

    /** Shape one service row for the table. */
    public static function toRow(BotService $service, int $orders = 0): array
    {
        $profit = $service->profit();

        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'quality' => $service->quality,
            'speed' => $service->speed,
            'dropInfo' => $service->drop_info,
            'refillInfo' => $service->refill_info,
            // Loaded into the edit form: without it saving any edit wrote the
            // link help back as empty.
            'linkInstructions' => $service->link_instructions,
            'platform' => $service->platform,
            'category' => $service->category,
            'providerServiceId' => $service->provider_service_id,
            'panel' => $service->panel?->name,
            'panelId' => $service->panel_id,
            'cost' => $service->cost_price === null ? null : (float) $service->cost_price,
            'price' => (float) $service->my_price,
            'profit' => $profit === null ? null : (float) $profit,
            'margin' => $service->margin(),
            'underwater' => $service->isUnderwater(),
            'minQuantity' => $service->min_quantity,
            'maxQuantity' => $service->max_quantity,
            'unitLabel' => $service->unit_label,
            'status' => $service->status,
            'featured' => $service->featured,
            'requiresApproval' => $service->requires_approval,
            'autoPaused' => $service->auto_paused,
            'orders' => $orders,
            'lastSyncedAt' => $service->last_synced_at?->toIso8601String(),
            'updatedAt' => $service->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  callable(BotService): void  $callback
     */
    public function exportChunks(ServiceFilters $filters, callable $callback): void
    {
        $this->build($filters)
            ->reorder()
            ->with('panel:id,name')
            ->chunkById(500, function ($services) use ($callback) {
                foreach ($services as $service) {
                    $callback($service);
                }
            }, 'bot_services.id', 'id');
    }
}
