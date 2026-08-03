<?php

namespace App\Services\Payments;

use App\Models\BotPayment;

/**
 * What happened when a top-up was started.
 *
 * Three outcomes the bot must say different things about: the reseller has no
 * usable gateway at all, the gateway refused, or it is now waiting — and in
 * that last case, whether the customer needs a link to open or a prompt to
 * approve on their handset.
 */
final readonly class TopupResult
{
    private function __construct(
        public bool $started,
        public ?BotPayment $payment,
        public ?string $redirectUrl,
        public ?string $message,
        public bool $noGateway,
    ) {}

    public static function started(BotPayment $payment, ?string $redirectUrl): self
    {
        return new self(
            started: true,
            payment: $payment,
            redirectUrl: $redirectUrl,
            message: null,
            noGateway: false,
        );
    }

    /** The reseller has nothing connected that can take a payment. */
    public static function noGateway(): self
    {
        return new self(
            started: false,
            payment: null,
            redirectUrl: null,
            message: null,
            noGateway: true,
        );
    }

    public static function failed(string $message): self
    {
        return new self(
            started: false,
            payment: null,
            redirectUrl: null,
            message: $message,
            noGateway: false,
        );
    }

    /** Mobile money pushes to a handset; everything else returns a link. */
    public function isPush(): bool
    {
        return $this->started && $this->redirectUrl === null;
    }
}
