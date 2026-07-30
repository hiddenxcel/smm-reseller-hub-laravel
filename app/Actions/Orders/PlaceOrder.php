<?php

namespace App\Actions\Orders;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use Illuminate\Support\Facades\DB;

/**
 * Charges a customer's wallet and records the order, as one atomic step.
 *
 * This replaces two divergent code paths in the old platform
 * (OrderBotHandler::completeFromWallet and WalletTopup::placePendingOrder),
 * both of which debited the wallet and *then* created the order with no
 * transaction around them. A failure in between charged the customer for an
 * order that never existed. Here the debit and the order row commit together
 * or not at all.
 *
 * Submitting the order to the reseller's panel is deliberately NOT part of
 * this: it is a third-party HTTP call that can hang or fail, and holding a
 * database transaction open across it would be worse than the bug being
 * fixed. The order is recorded first, then dispatched — see SubmitOrderToPanel.
 */
class PlaceOrder
{
    /**
     * @param  array{panel_id: ?int, provider_service_id: string, name: string}  $service
     */
    public function handle(
        BotCustomer $customer,
        array $service,
        string $link,
        int $quantity,
        string $amount,
    ): PlaceOrderResult {
        if (bccomp((string) $customer->balance, $amount, 4) === -1) {
            return PlaceOrderResult::failed(PlaceOrderFailure::InsufficientFunds);
        }

        return DB::transaction(function () use ($customer, $service, $link, $quantity, $amount) {
            // The debit is conditional on sufficient balance inside the UPDATE
            // itself, so a concurrent debit cannot overdraw the wallet — the
            // loser sees false here and the transaction rolls back.
            if (! $customer->debit($amount)) {
                return PlaceOrderResult::failed(PlaceOrderFailure::ChargeFailed);
            }

            $order = BotOrder::create([
                'tenant_id' => $customer->tenant_id,
                'panel_id' => $service['panel_id'] ?? null,
                'customer_phone' => $customer->phone,
                'customer_id' => $customer->id,
                'service_id' => $service['provider_service_id'],
                'service_name' => $service['name'],
                'link' => $link,
                'quantity' => $quantity,
                'amount' => $amount,
                'payment_status' => 'paid',
                'paid_from' => 'wallet',
                'status' => 'pending',
            ]);

            return PlaceOrderResult::placed($order);
        });
    }
}
