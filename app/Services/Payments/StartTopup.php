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
     */
    public function handle(
        int $tenantId,
        BotCustomer $customer,
        string $amount,
        string $currency,
        string $phone = '',
        string $gateway = '',
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
        ]);

        $initiation = $client->initiate(new PaymentRequest(
            reference: $payment->transaction_ref,
            amount: $amount,
            currency: $currency,
            webhookUrl: route('webhooks.payment', $credentials->gateway),
            phone: $phone,
            customerName: $customer->name ?: 'Customer',
        ));

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
