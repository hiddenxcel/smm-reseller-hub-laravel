<?php

namespace App\Services\Orders;

use App\Models\BotOrder;
use App\Models\TenantPanel;
use App\Services\Panel\SmmProviderClient;
use Illuminate\Support\Facades\DB;

/**
 * Ask each provider where the orders it was given have got to, and act on it.
 *
 * Until this existed an order was marked "processing" when it reached the
 * provider and never heard anything again, so the reseller's list, the
 * customer's tracking and any refund all depended on someone looking at the
 * panel by hand.
 *
 * Only orders that are still open are asked about, in one call per panel per
 * hundred. An order the provider has finished with — delivered, cancelled,
 * partial — is left alone from then on; the refund for a cancelled or partial
 * one is made at the moment its status changes to that.
 */
class SyncOrderStatuses
{
    /** Orders per panel call, and per run — a ceiling, not a target. */
    private const BATCH = 100;

    private const PER_RUN = 1000;

    /** Orders older than this are not worth asking about any more. */
    private const GIVE_UP_AFTER_DAYS = 30;

    public function __construct(private RefundOrder $refunds) {}

    /**
     * @return array{checked: int, updated: int, refunded: int}
     */
    public function run(?int $tenantId = null): array
    {
        $summary = ['checked' => 0, 'updated' => 0, 'refunded' => 0];

        $orders = $this->openOrders($tenantId);

        foreach ($orders->groupBy('panel_id') as $panelId => $group) {
            $panel = TenantPanel::withoutTenantScope()->find($panelId);

            if ($panel === null) {
                continue;
            }

            $client = SmmProviderClient::forPanel($panel);

            foreach ($group->chunk(self::BATCH) as $chunk) {
                $byProviderId = $chunk->keyBy(fn (BotOrder $order) => (string) $order->provider_order_id);

                $statuses = $client->checkStatuses($byProviderId->keys()->map(fn ($id) => (string) $id)->all());

                if ($statuses === null) {
                    continue; // the panel could not be asked; try again next time
                }

                foreach ($statuses as $providerId => $reported) {
                    /** @var BotOrder|null $order */
                    $order = $byProviderId->get((string) $providerId);

                    if ($order === null) {
                        continue;
                    }

                    $summary['checked']++;

                    if ($this->apply($order, $reported)) {
                        $summary['updated']++;
                    }

                    if ($this->refunds->handle($order) !== null) {
                        $summary['refunded']++;
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * Record what the provider said. Returns whether anything changed.
     *
     * @param  array{status: ?string, remains: ?int}  $reported
     */
    private function apply(BotOrder $order, array $reported): bool
    {
        $changes = [];

        if ($reported['status'] !== null && $reported['status'] !== $order->status) {
            $changes['status'] = mb_substr($reported['status'], 0, 30);
        }

        if ($reported['remains'] !== null && $reported['remains'] !== $order->remains) {
            $changes['remains'] = $reported['remains'];
        }

        if ($changes === []) {
            return false;
        }

        $order->update($changes);

        return true;
    }

    /**
     * Orders that reached a provider and have not finished.
     *
     * @return \Illuminate\Support\Collection<int, BotOrder>
     */
    private function openOrders(?int $tenantId)
    {
        $query = BotOrder::withoutTenantScope()
            ->whereNotNull('provider_order_id')
            ->whereNotNull('panel_id')
            ->where('created_at', '>=', now()->subDays(self::GIVE_UP_AFTER_DAYS))
            // Only the shops that are still trading.
            ->whereIn('tenant_id', DB::table('tenants')->where('status', 'active')->select('id'))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId));

        // Not finished: its status matches none of the words that mean "done".
        foreach (array_merge(OrderStatus::GROUPS[OrderStatus::COMPLETED], OrderStatus::GROUPS[OrderStatus::FAILED]) as $word) {
            $query->whereRaw('lower(coalesce(status, \'\')) not like ?', ["%{$word}%"]);
        }

        return $query->orderBy('id')->limit(self::PER_RUN)->get();
    }
}