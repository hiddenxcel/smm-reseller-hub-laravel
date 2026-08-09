<?php

namespace App\Services\Payments;

/**
 * A gateway that can be asked how a payment went.
 *
 * Two kinds of client need this, for opposite reasons:
 *
 *   Pesapal sends a notification that deliberately omits the status, so the
 *   webhook is only a nudge and this call is what decides.
 *
 *   Binance sends nothing at all — the payer quotes their own order id and we
 *   look it up.
 *
 * Implemented rather than assumed: PaymentWebhookController used to reach for
 * checkStatus() with method_exists(), which quietly returned "not confirmed"
 * for any client that spelled it differently.
 */
interface StatusCheckable
{
    /**
     * @param  string  $reference  whatever the gateway identifies the payment
     *                             by — its own id, not always ours
     * @return string|null lowercase status, or null if it could not be reached
     */
    public function checkStatus(string $reference): ?string;
}
