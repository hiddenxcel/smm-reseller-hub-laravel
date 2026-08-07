<?php

namespace App\Actions\Payments;

use App\Actions\Orders\PlaceOrder;
use App\Jobs\SubmitOrderToPanel;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Services\Bots\Order\OrderState;
use App\Services\Customers\CustomerReferrals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Called when a gateway confirms a payment. Credits the wallet and, if the
 * customer was part-way through an order when they went to pay, places it.
 *
 * Gateways retry webhooks — sometimes for hours — so this must be safe to
 * call repeatedly with the same payment. markSuccess() is a compare-and-swap:
 * only the first call proceeds, and every retry after it is a no-op.
 */
class CompleteTopup
{
    public function __construct(private PlaceOrder $placeOrder) {}

    public function handle(BotPayment $payment): void
    {
        // The replay guard. Everything below runs at most once per payment.
        if (! $payment->markSuccess()) {
            return;
        }

        $customer = BotCustomer::withoutTenantScope()->find($payment->customer_id);

        if ($customer === null) {
            Log::warning('Top-up confirmed for a customer that no longer exists', [
                'payment_id' => $payment->id,
            ]);

            return;
        }

        $customer->credit((string) $payment->amount);

        // Whoever introduced this customer earns a cut of their first top-up.
        // Safe on every payment: it pays out at most once, guarded by the same
        // first_deposit_done flag it sets.
        CustomerReferrals::payFirstDepositBonus($customer, (string) $payment->amount);

        $this->placePendingOrder($payment, $customer);
    }

    /**
     * A top-up started from inside an order carries that order in the
     * conversation, so paying should finish what the customer was doing
     * rather than leaving them to start again.
     */
    private function placePendingOrder(BotPayment $payment, BotCustomer $customer): void
    {
        $conversation = BotConversation::current($payment->tenant_id, $customer->phone, 'order');

        if ($conversation === null) {
            return;
        }

        $awaitingPayment = in_array($conversation->state, [
            OrderState::AwaitingPayment->value,
            OrderState::AwaitingBinanceOrder->value,
        ], true);

        if (! $awaitingPayment) {
            return;
        }

        $context = $conversation->context ?? [];

        // A standalone top-up has no order attached — the money simply lands
        // in the wallet.
        if (blank($context['service'] ?? null) || blank($context['amount'] ?? null)) {
            return;
        }

        $customer->refresh();

        // A partial top-up still leaves them short; the funds stay put rather
        // than half-placing anything.
        if (bccomp((string) $customer->balance, (string) $context['amount'], 4) === -1) {
            return;
        }

        $result = DB::transaction(fn () => $this->placeOrder->handle(
            customer: $customer,
            service: $context['service'],
            link: $context['link'],
            quantity: (int) $context['quantity'],
            amount: (string) $context['amount'],
        ));

        BotConversation::clear($payment->tenant_id, $customer->phone, 'order');

        if ($result->placed) {
            SubmitOrderToPanel::dispatch($result->order->id);
        }
    }
}
