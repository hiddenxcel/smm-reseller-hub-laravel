<?php

namespace App\Services\Payments;

/**
 * Whether a gateway's answer to "was this paid?" means the money has arrived.
 *
 * Every gateway words it differently — Paystack says "success", Razorpay
 * "paid", NOWPayments "finished", Snippe "completed", and the clients written
 * most recently already boil theirs down to "completed". Reading the wrong word
 * as paid credits a wallet for money that never came, so this is a short list
 * of exactly what each means, and anything else — including a status nobody has
 * seen before — is not paid.
 */
final class PaymentOutcome
{
    public static function isPaid(string $gateway, ?string $answer): bool
    {
        if ($answer === null || $answer === '') {
            return false;
        }

        $answer = strtolower(trim($answer));

        return match (true) {
            $gateway === 'nowpayments' => NowPaymentsClient::isPaidStatus($answer),
            $gateway === 'paystack' => $answer === 'success',
            $gateway === 'razorpay' => $answer === 'paid',
            default => $answer === 'completed',
        };
    }

    /**
     * Which id to ask the gateway about.
     *
     * Most want the id they issued, which is the payment's gateway reference.
     * Paystack and Razorpay look a payment up by the reference WE gave them.
     */
    public static function referenceFor(string $gateway, string $ourReference, ?string $gatewayReference): string
    {
        if (in_array($gateway, ['paystack', 'razorpay'], true)) {
            return $ourReference;
        }

        return filled($gatewayReference) ? $gatewayReference : $ourReference;
    }
}