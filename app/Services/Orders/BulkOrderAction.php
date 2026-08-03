<?php

namespace App\Services\Orders;

use App\Models\BotOrder;
use App\Models\Tenant;

/**
 * Runs one action across a selection of orders.
 *
 * Two rules shape this. First, orders are re-fetched by id scoped to the
 * tenant, so a hand-edited id list cannot touch another reseller's rows.
 * Second, an order the action does not apply to is *skipped*, not failed —
 * selecting a page and pressing "Cancel" should cancel what can be cancelled
 * and say how many it left alone, rather than refusing the lot.
 *
 * Refill and cancel each make a panel HTTP call per order, so the selection is
 * capped. A reseller who really means to cancel 5,000 orders is better served
 * by narrowing the filter than by holding one request open for ten minutes.
 */
class BulkOrderAction
{
    public const MAX_SELECTION = 500;

    /** Actions that hit the panel once per order, and so are capped harder. */
    private const PANEL_ACTIONS = [OrderActions::REFILL, OrderActions::CANCEL];

    public const MAX_PANEL_SELECTION = 100;

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public static function limitFor(string $action): int
    {
        return in_array($action, self::PANEL_ACTIONS, true)
            ? self::MAX_PANEL_SELECTION
            : self::MAX_SELECTION;
    }

    /**
     * @param  list<int>  $ids
     * @return array{done: int, skipped: int, failed: int, message: string}
     */
    public function run(string $action, array $ids, ?string $markAs = null): array
    {
        $orders = BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('id', array_slice($ids, 0, self::limitFor($action)))
            ->with('panel')
            ->get();

        $done = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($orders as $order) {
            if (! OrderActions::isAvailable($order, $action)) {
                $skipped++;

                continue;
            }

            $result = match ($action) {
                OrderActions::RETRY => OrderActions::retry($order),
                OrderActions::REFILL => OrderActions::refill($order),
                OrderActions::CANCEL => OrderActions::cancel($order),
                OrderActions::MARK => OrderActions::markStatus($order, (string) $markAs),
            };

            $result->failed ? $failed++ : $done++;
        }

        return [
            'done' => $done,
            'skipped' => $skipped,
            'failed' => $failed,
            'message' => $this->describe($action, $done, $skipped, $failed),
        ];
    }

    private function describe(string $action, int $done, int $skipped, int $failed): string
    {
        $verb = match ($action) {
            OrderActions::RETRY => 'sent to the panel',
            OrderActions::REFILL => 'sent for refill',
            OrderActions::CANCEL => 'cancelled',
            OrderActions::MARK => 'updated',
        };

        if ($done === 0 && $skipped > 0 && $failed === 0) {
            return "Nothing to do — none of the {$skipped} selected orders can be {$verb}.";
        }

        $parts = [$done.' '.str('order')->plural($done)." {$verb}"];

        if ($skipped > 0) {
            $parts[] = "{$skipped} skipped";
        }

        if ($failed > 0) {
            $parts[] = "{$failed} failed";
        }

        return implode(', ', $parts).'.';
    }
}
