<?php

namespace App\Services\Payments;

/**
 * What every payment client can do: take money from a customer.
 *
 * This is the whole contract the top-up flow depends on. StartTopup and the
 * order bot talk to this and nothing else, so adding a gateway means writing a
 * client and naming it in GatewayFactory — no business logic changes.
 *
 * Deliberately small. The things only some gateways do — verifying a webhook
 * signature, being asked for a status, registering a notification URL — are
 * separate interfaces (WebhookVerifier, StatusCheckable, IpnRegistrar) that a
 * client adds when it needs them. Callers check for those with `instanceof`
 * rather than method_exists(), so a typo in a method name is a type error
 * rather than a payment that silently never confirms.
 */
interface PaymentGateway
{
    /**
     * Ask the gateway to collect a payment.
     *
     * Never throws for a gateway that refuses or cannot be reached: those come
     * back as PaymentInitiation::failed with something the customer can be
     * told, because a top-up that fails must still leave a payment row behind.
     *
     * @param  string  $description  what the payer sees on the checkout page
     */
    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation;
}
