<?php

namespace App\Services\Api\Actions;

use App\Actions\Orders\PlaceOrder;
use App\Actions\Orders\PlaceOrderFailure;
use App\Jobs\SubmitOrderToPanel;
use App\Models\ApiKey;
use App\Models\BotService;
use App\Services\Api\ApiResult;
use App\Services\Api\OrderPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * `action=add` — place an order and charge the customer's wallet.
 *
 * This is the same PlaceOrder the WhatsApp bot uses, on purpose: the debit and
 * the order row have to commit together, and there must not be a second path
 * that gets that subtly wrong. The panel submission is queued afterwards for
 * the same reason it is in the bot — a 30-second HTTP call inside a request
 * the caller will retry is how duplicate orders get made.
 *
 * A service that requires the reseller's approval is refused here rather than
 * queued: approval is a WhatsApp conversation, and an API caller has no way to
 * take part in one. Better a clear refusal than an order that silently waits.
 */
class AddAction implements ApiAction
{
    public function handle(Request $request, ApiKey $key): ApiResult
    {
        $validator = Validator::make($request->all(), [
            'service' => ['required'],
            'link' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return ApiResult::error($validator->errors()->first());
        }

        $service = BotService::query()->find($request->input('service'));

        if ($service === null) {
            return ApiResult::error('Invalid service');
        }

        if ($service->status !== BotService::ACTIVE) {
            return ApiResult::error('This service is not available right now');
        }

        if ($service->requires_approval) {
            return ApiResult::error('This service cannot be ordered over the API');
        }

        $quantity = (int) $request->input('quantity');

        if ($quantity < $service->min_quantity || $quantity > $service->max_quantity) {
            return ApiResult::error(
                "Quantity must be between {$service->min_quantity} and {$service->max_quantity}",
            );
        }

        $amount = OrderPricing::charge($service, $quantity);
        $customer = $key->customer;

        $result = app(PlaceOrder::class)->handle(
            customer: $customer,
            service: [
                'panel_id' => $service->panel_id,
                'provider_service_id' => $service->provider_service_id,
                'name' => $service->name,
                'cost_price' => $service->cost_price === null ? null : (string) $service->cost_price,
            ],
            link: (string) $request->input('link'),
            quantity: $quantity,
            amount: $amount,
        );

        if (! $result->placed) {
            return ApiResult::error(match ($result->failure) {
                PlaceOrderFailure::InsufficientFunds => 'Not enough balance',
                // A concurrent debit won the race. Not the caller's fault and
                // not permanent, so it is worth retrying — say so.
                PlaceOrderFailure::ChargeFailed => 'Could not charge your balance, please try again',
                default => 'Order could not be placed',
            }, ['service_id' => $service->id, 'amount' => $amount]);
        }

        SubmitOrderToPanel::dispatch($result->order->id);

        return ApiResult::ok(
            // The v2 convention: an integer under `order`, nothing else.
            ['order' => $result->order->id],
            ['order_id' => $result->order->id, 'service_id' => $service->id, 'amount' => $amount],
        );
    }
}
