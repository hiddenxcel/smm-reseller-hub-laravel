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
    ) {}
}
