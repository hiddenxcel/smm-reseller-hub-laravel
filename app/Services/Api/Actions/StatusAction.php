<?php

namespace App\Services\Api\Actions;

use App\Models\ApiKey;
use App\Models\BotOrder;
use App\Services\Api\ApiResult;
use App\Services\Api\OrderView;
use Illuminate\Http\Request;

/**
 * `action=status` — where one order has got to.
 *
 * Scoped to the key's own customer, not just the tenant: two customers of the
 * same reseller must not be able to read each other's orders by guessing an
 * id, and ids are sequential.
 */
class StatusAction implements ApiAction
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

        return ApiResult::ok(OrderView::of($order), ['order_id' => $order->id]);
    }
}
