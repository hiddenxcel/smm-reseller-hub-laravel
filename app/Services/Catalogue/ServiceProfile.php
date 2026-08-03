<?php

namespace App\Services\Catalogue;

use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\ServicePriceHistory;
use App\Services\Orders\OrderStatus;

/**
 * Everything the drawer shows about one service.
 *
 * Each tab is its own method, fetched on demand — opening a service costs one
 * query, not five, and a tab nobody clicks costs nothing.
 *
 * Orders are matched on (panel_id, provider_service_id) rather than on our own
 * row id: bot_orders records the PANEL's service id, because that is what the
 * panel will answer questions about. Keying on ours would silently show zero
 * orders for every service.
 */
class ServiceProfile
{
    public function __construct(private BotService $service) {}

    public static function for(BotService $service): self
    {
        return new self($service);
    }

    public function overview(): array
    {
        $orders = $this->ordersQuery()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(amount), 0) as revenue')
            ->selectRaw('coalesce(sum(charge), 0) as spend')
            ->selectRaw("count(*) filter (where status ilike '%complet%') as completed")
            ->first();

        $revenue = (float) ($orders->revenue ?? 0);
        $spend = (float) ($orders->spend ?? 0);

        return [
            'id' => $this->service->id,
            'name' => $this->service->name,
            'description' => $this->service->description,
            'platform' => $this->service->platform,
            'category' => $this->service->category,
            'providerServiceId' => $this->service->provider_service_id,
            'panel' => $this->service->panel?->name,
            'panelId' => $this->service->panel_id,
            'unitLabel' => $this->service->unit_label,
            'minQuantity' => $this->service->min_quantity,
            'maxQuantity' => $this->service->max_quantity,
            'linkInstructions' => $this->service->link_instructions,
            'status' => $this->service->status,
            'autoPaused' => $this->service->auto_paused,
            'featured' => $this->service->featured,
            'requiresApproval' => $this->service->requires_approval,
            'lastSyncedAt' => $this->service->last_synced_at?->toIso8601String(),
            'createdAt' => $this->service->created_at?->toIso8601String(),
            'updatedAt' => $this->service->updated_at?->toIso8601String(),

            // What it has actually done, which is the question the numbers on
            // the pricing tab cannot answer.
            'performance' => [
                'orders' => (int) ($orders->total ?? 0),
                'completed' => (int) ($orders->completed ?? 0),
                'revenue' => round($revenue, 2),
                // Real profit taken, not the theoretical margin: what the
                // panel actually charged, against what customers actually paid.
                'profit' => $spend > 0 ? round($revenue - $spend, 2) : null,
            ],
        ];
    }

    public function pricing(): array
    {
        $profit = $this->service->profit();

        return [
            'cost' => $this->service->cost_price === null ? null : (float) $this->service->cost_price,
            'price' => (float) $this->service->my_price,
            'profit' => $profit === null ? null : (float) $profit,
            'margin' => $this->service->margin(),
            'underwater' => $this->service->isUnderwater(),
            'syncedCost' => $this->service->synced_cost_price === null
                ? null
                : (float) $this->service->synced_cost_price,
            'history' => $this->history(),
        ];
    }

    /**
     * Recent orders for this service.
     *
     * @return list<array<string, mixed>>
     */
    public function orders(int $limit = 25): array
    {
        return $this->ordersQuery()
            ->with('customer:id,name,phone')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BotOrder $order) => [
                'id' => $order->id,
                'customer' => $order->customer?->name ?? $order->customer_phone,
                'quantity' => $order->quantity,
                'charge' => $order->amount === null ? null : (float) $order->amount,
                'cost' => $order->charge === null ? null : (float) $order->charge,
                'profit' => $order->amount !== null && $order->charge !== null
                    ? round((float) $order->amount - (float) $order->charge, 2)
                    : null,
                'status' => OrderStatus::fold($order->status),
                'rawStatus' => $order->status,
                'at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The log: every price change, and what caused it.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 40): array
    {
        return ServicePriceHistory::withoutTenantScope()
            ->where('service_id', $this->service->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ServicePriceHistory $entry) => [
                'id' => $entry->id,
                'reason' => $entry->reason,
                'oldPrice' => $entry->old_price === null ? null : (float) $entry->old_price,
                'newPrice' => (float) $entry->new_price,
                'oldCost' => $entry->old_cost === null ? null : (float) $entry->old_cost,
                'newCost' => $entry->new_cost === null ? null : (float) $entry->new_cost,
                'note' => $entry->note,
                'at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function settings(): array
    {
        return [
            'status' => $this->service->status,
            'featured' => $this->service->featured,
            'requiresApproval' => $this->service->requires_approval,
            'sortOrder' => $this->service->sort_order,
            'linkInstructions' => $this->service->link_instructions,
            'unitLabel' => $this->service->unit_label,
            'minQuantity' => $this->service->min_quantity,
            'maxQuantity' => $this->service->max_quantity,
        ];
    }

    private function ordersQuery()
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->service->tenant_id)
            ->where('service_id', $this->service->provider_service_id)
            ->where(function ($query) {
                // Two panels can hand out the same service id, so the panel is
                // part of the key. A catalogue-only service has no panel and
                // matches orders that have none either.
                $this->service->panel_id === null
                    ? $query->whereNull('panel_id')
                    : $query->where('panel_id', $this->service->panel_id);
            });
    }
}
