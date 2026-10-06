<?php

namespace App\Services\Payments;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use Illuminate\Support\Str;

/**
 * Starts a wallet top-up: records the intent, then asks the gateway to collect.
 *
 * The BotPayment row is written *before* the gateway is called, and its
 * reference is what the gateway is told to send back. That order matters —
 * gateways have been known to deliver a webhook before their own HTTP response
 * arrives, and a webhook for a payment we have not recorded yet is one we
 * cannot credit.
 *
 * A failed initiation leaves the row behind as 'failed' rather than deleting
 * it, so a reseller can see that a customer tried and the gateway refused.
 */
class StartTopup
{
    public function __construct(private GatewayFactory $factory) {}

    /**
     * @param  string  $amount  decimal string, in the shop's currency
     * @param  string  $phone  the payer's number, for mobile money only
     * @param  string  $gateway  the one the customer picked; blank lets the
     *                           reseller's default decide, which is what a
     *                           single-gateway shop wants
     * @param  array{service: array, link: string, quantity: int, amount: string}|null  $pendingOrder
     *                           the order this payment is for, placed when it clears
     */
    public function handle(
        int $tenantId,
        BotCustomer $customer,
        string $amount,
        string $currency,
        string $phone = '',
        string $gateway = '',
        ?array $pendingOrder = null,
    ): TopupResult {
        // A named gateway is still looked up through the factory, so a customer
        // replying with the code of one that has since been paused gets the
        // no-gateway message rather than a payment nobody can complete.
        $credentials = $gateway !== ''
            ? $this->factory->usableGateway($tenantId, $gateway)
            : $this->factory->firstUsableFor($tenantId);

        if ($credentials === null) {
            return TopupResult::noGateway();
        }

        $client = $this->factory->make($credentials);

        if ($client === null) {
            // firstUsableFor already filters on isReady, so reaching here means
            // config and code disagree — a gateway marked ready with no client.
            return TopupResult::noGateway();
        }

        $payment = BotPayment::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'type' => 'wallet_topup',
            'customer_id' => $customer->id,
            'gateway' => $credentials->gateway,
            'transaction_ref' => $this->reference(),
            'amount' => $amount,
            'status' => 'pending',
            // What the customer is paying for, if anything, so that paying
            // finishes it however the conversation has moved on since.
            'pending_order' => $pendingOrder,
        ]);

        $initiation = $client->initiate(new PaymentRequest(
            reference: $payment->transaction_ref,
            amount: $amount,
            currency: $currency,
            webhookUrl: route('webhooks.payment', $credentials->gateway),
            // A gateway the bot did not ask a number for (a card checkout, a
            // hosted page) still sometimes wants one on file, and the customer
            // is already talking to us from theirs.
            phone: $phone !== '' ? $phone : preg_replace('/\D/', '', (string) $customer->phone),
            customerName: $customer->name ?: 'Customer',
        ));

        // Remembered so the webhook can find this payment by the id the gateway
        // knows it by, when that is all the webhook carries.
        if ($initiation->started && filled($initiation->reference)) {
            $payment->update(['gateway_reference' => $initiation->reference]);
        }

        if (! $initiation->started) {
            $payment->update(['status' => 'failed']);

            return TopupResult::failed($initiation->message ?? 'Payment could not be started');
        }

        return TopupResult::started($payment, $initiation->redirectUrl);
    }

    /**
     * Unique across the platform, since the webhook looks a payment up by this
     * alone — it arrives with no session and no tenant.
     */
    private function reference(): string
    {
        return 'tu_'.Str::lower(Str::ulid());
    }
}
