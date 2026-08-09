<?php

namespace App\Services\Payments;

/**
 * What every gateway needs to start a payment. `reference` is our own
 * transaction_ref — it is what the webhook sends back, and what the
 * idempotency guards key on.
 */
final readonly class PaymentRequest
{
    public function __construct(
        public string $reference,
        public string $amount,
        public string $currency,
        public string $webhookUrl,
        public string $phone = '',
        public string $customerName = 'Customer',
        /**
         * Empty for a chat customer, who has never given one — callers that
         * know an email (billing knows the reseller's) pass it, and clients
         * that must send something fall back to placeholderEmail().
         */
        public string $customerEmail = '',
    ) {}

    /**
     * An address in a domain that can never receive mail, for gateways that
     * reject a blank email but are only ever told about a WhatsApp customer.
     *
     * Unique per payment so a gateway keying its own records off the address
     * does not merge two unrelated customers into one.
     */
    public function emailOrPlaceholder(): string
    {
        return $this->customerEmail !== ''
            ? $this->customerEmail
            : "{$this->reference}@no-reply.invalid";
    }
}
