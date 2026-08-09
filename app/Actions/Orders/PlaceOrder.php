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
     * @param  array{panel_id: ?int, provider_service_id: string, name: string, cost_price?: ?string}  $service
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
                'charge' => $this->costOf($service, $quantity),
                'payment_status' => 'paid',
                'paid_from' => 'wallet',
                'status' => 'pending',
            ]);

            return PlaceOrderResult::placed($order);
        });
    }

    /**
     * What this order costs the reseller at the panel, recorded alongside what
     * the customer paid.
     *
     * Without this, `charge` was never written and every margin the app
     * displays — per order, per service, per customer — was permanently null.
     *
     * It is a snapshot, not a live lookup: costs move, and an order's margin
     * is a fact about the day it was placed. Recomputing it later from the
     * current cost would quietly rewrite history, and a reseller reviewing a
     * bad month would be shown today's numbers instead of that month's.
     *
     * Per 1,000 units, matching OrderPricing::charge — the same basis the
     * selling price is quoted on, so the two subtract meaningfully. Null when
     * the panel never reported a cost, which BotService::profit() already
     * treats as "unknown" rather than "free".
     */
    private function costOf(array $service, int $quantity): ?string
    {
        $cost = $service['cost_price'] ?? null;

        if ($cost === null) {
            return null;
        }

        return bcdiv(bcmul((string) $cost, (string) $quantity, 4), '1000', 4);
    }
}
