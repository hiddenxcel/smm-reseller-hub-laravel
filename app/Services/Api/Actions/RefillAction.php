<?php

namespace App\Services\Api\Actions;

use App\Models\ApiKey;
use App\Models\BotOrder;
use App\Services\Api\ApiResult;
use App\Services\Orders\OrderActions;
use Illuminate\Http\Request;

/**
 * `action=refill` — ask the panel to top an order back up.
 *
 * Runs through OrderActions, which already decides when a refill is possible
 * (delivered, and known to the panel) and talks to the panel. A second
 * implementation here would be a second set of rules to keep in step.
 *
 * Unlike the rest of the API this makes a live call to the reseller's panel
 * rather than queueing one, because the convention expects the refill id back
 * in the response. Panels answer this one quickly; if that turns out to be
 * optimistic in practice, it is the call to revisit.
 */
class RefillAction implements ApiAction
{
    public function handle(Request $request, ApiKey $key): ApiResult
    {
        $id = $request->input('order');

        if (! is_scalar($id) || (string) $id === '') {
            return ApiResult::error('Order is required');
        }

        $order = BotOrder::query()
            ->where('customer_id', $key->customer_id)
            ->find($id);

        if ($order === null) {
            return ApiResult::error('Incorrect order ID');
        }

        if (! OrderActions::isAvailable($order, OrderActions::REFILL)) {
            return ApiResult::error('This order cannot be refilled');
        }

        $result = OrderActions::refill($order);

        if ($result->failed) {
            return ApiResult::error($result->message, ['order_id' => $order->id]);
        }

        // No refill-tracking table exists, so the order's own id is returned
        // as the refill id. It is what a caller would poll with, and it
        // resolves — which a fabricated number would not.
        return ApiResult::ok(['refill' => (string) $order->id], ['order_id' => $order->id]);
    }
}
