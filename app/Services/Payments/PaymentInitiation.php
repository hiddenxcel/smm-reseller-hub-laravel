<?php

namespace App\Services\Payments;

/**
 * The result of asking a gateway to start a payment.
 *
 * Gateways split into two shapes: mobile money pushes a prompt to the payer's
 * handset (no URL to open), while crypto gateways return a checkout page to
 * redirect to. Callers branch on redirectUrl being present.
 */
final readonly class PaymentInitiation
{
    private function __construct(
        public bool $started,
        public ?string $reference,
        public ?string $redirectUrl,
        public ?string $message,
    ) {}

    /** Mobile money: a prompt is now on the payer's phone. */
    public static function pushed(string $reference): self
    {
        return new self(started: true, reference: $reference, redirectUrl: null, message: null);
    }

    /** Crypto/card: send the payer to this checkout page. */
    public static function redirect(string $reference, string $url): self
    {
        return new self(started: true, reference: $reference, redirectUrl: $url, message: null);
    }

    public static function failed(string $message): self
    {
        return new self(started: false, reference: null, redirectUrl: null, message: $message);
    }
}
