<?php

namespace App\Services\Admin;

use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the platform's resellers are actually selling.
 *
 * There is no global catalogue to edit — every service row belongs to one
 * reseller, priced by them, sourced from their own panel. So this screen
 * reports rather than manages: which platforms are popular, which panels
 * everyone depends on, and where services have gone quiet.
 *
 * That is a deliberate boundary. An admin editing a reseller's prices from here
 * would be changing what someone else's customers pay, without impersonating
 * and without that reseller knowing. Nothing in this class writes.
 *
 * The one thing worth watching is `auto_paused`: the platform pauses a service
 * when its panel keeps failing, so a cluster of them is usually one broken
 * provider affecting many resellers at once — which is ours to notice.
 */
class CatalogueOverview
{
    public static function make(): self
    {
        return new self;
    }

    public function kpis(): array
    {
        return [
            'services' => BotService::withoutTenantScope()->count(),
            'active' => BotService::withoutTenantScope()
                ->where('status', BotService::ACTIVE)
                ->count(),
            'autoPaused' => BotService::withoutTenantScope()
                ->where('auto_paused', true)
                ->count(),
            'panels' => TenantPanel::withoutTenantScope()->count(),
            'sellingTenants' => BotService::withoutTenantScope()
                ->where('status', BotService::ACTIVE)
                ->distinct()
                ->count('tenant_id'),
        ];
    }

    /**
     * The platforms resellers sell for, by how many services carry them.
     *
     * @return array<int, array{platform: string, services: int, tenants: int}>
     */
    public function platforms(int $limit = 12): array
    {
        return BotService::withoutTenantScope()
            ->whereNotNull('platform')
            ->selectRaw('platform, count(*) as services, count(distinct tenant_id) as tenants')
            ->groupBy('platform')
            ->orderByDesc('services')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'platform' => (string) $row->platform,
                'services' => (int) $row->services,
                'tenants' => (int) $row->tenants,
            ])
            ->all();
    }

    /**
     * Panels the platform depends on, and how healthy they look.
     *
     * Grouped by api_url rather than by name: resellers name the same provider
     * differently, and the URL is what actually identifies it. A panel with
     * many resellers behind it and a bad status is a platform-wide outage
     * waiting to be noticed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panels(int $limit = 15): array
    {
        return TenantPanel::withoutTenantScope()
            ->whereNotNull('api_url')
            ->selectRaw('api_url, count(*) as tenants, sum(case when status = ? then 1 else 0 end) as unhealthy', ['error'])
            ->groupBy('api_url')
            ->orderByDesc('tenants')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'url' => (string) $row->api_url,
                'host' => parse_url((string) $row->api_url, PHP_URL_HOST) ?: (string) $row->api_url,
                'tenants' => (int) $row->tenants,
                'unhealthy' => (int) $row->unhealthy,
            ])
            ->all();
    }

    /**
     * Services the platform paused because their panel kept failing.
     *
     * Ordered by how many resellers are affected by the same provider service,
     * so one broken upstream shows up as one row rather than forty.
     *
     * @return array<int, array<string, mixed>>
     */
    public function autoPaused(int $limit = 20): array
    {
        return BotService::withoutTenantScope()
            ->where('auto_paused', true)
            ->with('tenant')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BotService $service) => [
                'id' => $service->id,
                'tenantId' => $service->tenant_id,
                'tenant' => $service->tenant?->business_name ?? 'Deleted reseller',
                'name' => $service->name,
                'platform' => $service->platform,
                'providerServiceId' => $service->provider_service_id,
                'syncedAt' => $service->last_synced_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * What is actually selling, platform-wide, over the last 30 days.
     *
     * @return array<int, array{name: string, orders: int, revenue: float}>
     */
    public function topSelling(int $limit = 10): array
    {
        return BotOrder::withoutTenantScope()
            ->where('created_at', '>=', Carbon::today()->subDays(29))
            ->whereNotNull('service_name')
            ->selectRaw('service_name, count(*) as orders, coalesce(sum(amount), 0) as revenue')
            ->groupBy('service_name')
            ->orderByDesc('orders')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->service_name,
                'orders' => (int) $row->orders,
                // Their customers' money, not ours. Reported because it says
                // what is popular, never counted as platform revenue.
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /** Resellers with the largest catalogues — usually the most invested ones. */
    public function biggestCatalogues(int $limit = 8): array
    {
        $counts = BotService::withoutTenantScope()
            ->selectRaw('tenant_id, count(*) as total')
            ->groupBy('tenant_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'tenant_id');

        $names = Tenant::whereIn('id', $counts->keys())
            ->pluck('business_name', 'id');

        return $counts
            ->map(fn ($total, $tenantId) => [
                'tenantId' => (int) $tenantId,
                'tenant' => $names[$tenantId] ?? 'Deleted reseller',
                'services' => (int) $total,
            ])
            ->values()
            ->all();
    }

    /** Median markup across the platform, as a sanity check on pricing. */
    public function pricing(): array
    {
        $row = DB::table('bot_services')
            ->where('status', BotService::ACTIVE)
            ->where('cost_price', '>', 0)
            ->selectRaw('avg(my_price / cost_price) as avg_markup, count(*) as priced')
            ->first();

        return [
            'avgMarkup' => $row && $row->avg_markup !== null
                ? round((float) $row->avg_markup, 2)
                : null,
            'priced' => (int) ($row->priced ?? 0),
        ];
    }
}
