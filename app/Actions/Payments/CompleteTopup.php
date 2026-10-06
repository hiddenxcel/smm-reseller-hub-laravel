<?php

namespace App\Actions\Payments;

use App\Actions\Orders\PlaceOrder;
use App\Jobs\NotifyPaymentCredited;
use App\Jobs\SubmitOrderToPanel;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Services\Bots\Order\OrderState;
use App\Services\Customers\CustomerReferrals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Called when a payment is confirmed — by a gateway's webhook, by the customer
 * coming back from its page, or by us asking the gateway (ReconcilePayments).
 * Credits the wallet and, if the customer was paying for an order, places it,
 * then tells them on WhatsApp.
 *
 * Money must never be lost, which shapes everything here:
 *
 *   - It is safe to call as often as you like with the same payment. Gateways
 *     retry for hours, and we may ask a gateway about a payment it has already
 *     told us about. markSuccess() is a compare-and-swap, so only the first
 *     call does anything.
 *   - Flipping the payment to "success" and crediting the wallet happen in ONE
 *     transaction. Done separately, a crash between the two left a payment
 *     marked paid and a wallet never credited — and the guard above then
 *     refused every retry.
 *   - The order is placed AFTER the wallet is credited and separately, so
 *     whatever goes wrong with the order, the customer's money is already in
 *     their wallet and they are told so.
 */
class CompleteTopup
{
    public function __construct(private PlaceOrder $placeOrder) {}

    public function handle(BotPayment $payment): void
    {
        $customer = BotCustomer::withoutTenantScope()->find($payment->customer_id);

        // A customer who no longer exists has nowhere for the money to go. The
        // payment is settled and logged loudly rather than left pending: asking
        // the gateway about it every hour for two days would change nothing, and
        // the reseller needs to see that real money arrived with no one to
        // credit it to.
        if ($customer === null) {
            $payment->markSuccess();

            Log::error('Payment confirmed for a customer that no longer exists', [
                'payment_id' => $payment->id,
                'amount' => (string) $payment->amount,
            ]);

            return;
        }

        $credited = DB::transaction(function () use ($payment, $customer) {
            // The replay guard. Everything below runs at most once per payment.
            if (! $payment->markSuccess()) {
                return false;
            }

            $customer->credit((string) $payment->amount);

            // Whoever introduced this customer earns a cut of their first
            // top-up. It pays out at most once, guarded by its own flag.
            CustomerReferrals::payFirstDepositBonus($customer, (string) $payment->amount);

            return true;
        });

        if (! $credited) {
            return;
        }

        $order = $this->placePendingOrder($payment, $customer);

        NotifyPaymentCredited::dispatch($payment->id, $order?->id)->afterCommit();
    }

    /**
     * Finish what the customer was paying for.
     *
     * The order is remembered on the payment itself, so this does not depend on
     * where the conversation has got to. The conversation is only a fallback,
     * for a payment started before orders were kept on payments.
     */
    private function placePendingOrder(BotPayment $payment, BotCustomer $customer): ?BotOrder
    {
        $order = $payment->pending_order ?? $this->orderFromConversation($payment, $customer);

        if (blank($order['service'] ?? null) || blank($order['amount'] ?? null) || blank($order['link'] ?? null)) {
            return null;
        }

        $customer->refresh();

        // A partial top-up still leaves them short; the funds stay put rather
        // than half-placing anything.
        if (bccomp((string) $customer->balance, (string) $order['amount'], 4) === -1) {
            return null;
        }

        $result = DB::transaction(fn () => $this->placeOrder->handle(
            customer: $customer,
            service: $order['service'],
            link: $order['link'],
            quantity: (int) $order['quantity'],
            amount: (string) $order['amount'],
        ));

        // The conversation was waiting for this payment: it is done now. Only
        // cleared if it is still waiting — the customer may have moved on to
        // something new that must not be wiped.
        $this->clearWaitingConversation($payment, $customer);

        if (! $result->placed) {
            return null;
        }

        SubmitOrderToPanel::dispatch($result->order->id);

        return $result->order;
    }

    /** @return array<string, mixed>|null */
    private function orderFromConversation(BotPayment $payment, BotCustomer $customer): ?array
    {
        $conversation = BotConversation::current($payment->tenant_id, $customer->phone, 'order');

        if ($conversation === null || ! $this->isWaitingForPayment($conversation)) {
            return null;
        }

        $context = $conversation->context ?? [];

        return [
            'service' => $context['service'] ?? null,
            'link' => $context['link'] ?? null,
            'quantity' => $context['quantity'] ?? 0,
            'amount' => $context['amount'] ?? null,
        ];
    }

    private function clearWaitingConversation(BotPayment $payment, BotCustomer $customer): void
    {
        $conversation = BotConversation::current($payment->tenant_id, $customer->phone, 'order');

        if ($conversation !== null && $this->isWaitingForPayment($conversation)) {
            BotConversation::clear($payment->tenant_id, $customer->phone, 'order');
        }
    }

    private function isWaitingForPayment(BotConversation $conversation): bool
    {
        return in_array($conversation->state, [
            OrderState::AwaitingPayment->value,
            OrderState::AwaitingBinanceOrder->value,
        ], true);
    }
}
