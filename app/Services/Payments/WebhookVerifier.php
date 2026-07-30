<?php

namespace App\Services\Payments;

/**
 * Verifies that a payment webhook really came from the gateway.
 *
 * Every client in the old platform did this:
 *
 *     if (empty($this->secret)) {
 *         return true; // no secret configured (dev) -> allow
 *     }
 *
 * which means a gateway saved without a webhook secret accepted
 * *unauthenticated* payment confirmations — anyone who knew the URL could
 * credit a wallet. Implementations here must fail closed instead: no secret
 * means no verification is possible, so the webhook is rejected.
 */
interface WebhookVerifier
{
    /**
     * @param  string  $body  the raw request body, exactly as received —
     *                        re-encoding JSON changes the bytes and breaks HMACs
     * @param  array<string, string>  $headers  signature/timestamp/nonce, per gateway
     */
    public function verifyWebhook(string $body, array $headers): bool;
}
