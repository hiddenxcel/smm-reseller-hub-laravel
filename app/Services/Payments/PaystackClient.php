<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cards, bank transfer and USSD across Nigeria, Ghana, Kenya and South Africa,
 * via a Paystack hosted checkout.
 *
 * initiate() creates a transaction and returns its authorization URL; Paystack
 * POSTs `charge.success` back when the payer finishes.
 *
 * Two things differ from the other African gateways here:
 *
 *   The webhook is signed with the *secret key* itself rather than a separate
 *   webhook secret — Paystack issues no second value — so both credentials are
 *   the same key. It is still stored twice rather than special-cased, because
 *   a reseller who rotates the key must rotate both.
 *
 *   Amounts are in the currency's minor unit (kobo, pesewas, cents), so the
 *   whole-unit amount is multiplied by 100. Every currency Paystack supports
 *   has one, so there is no zero-decimal exception list.
 */
class PaystackClient implements PaymentGateway, StatusCheckable, WebhookVerifier
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(
        private string $secretKey,
        private string $webhookSecret,
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->timeout(30)
                ->post(self::BASE_URL.'/transaction/initialize', [
                    // Our own reference: Paystack echoes it on the webhook as
                    // data.reference, which is how the payment is found again.
                    'reference' => $request->reference,
                    'amount' => $this->minorUnits($request->amount),
                    'currency' => strtoupper($request->currency),
                    'callback_url' => $request->webhookUrl,
                    // Paystack requires an email and a chat customer has never
                    // given one, so the reference stands in for it.
                    'email' => "{$request->reference}@no-reply.invalid",
                    'metadata' => [
                        'reference' => $request->reference,
                        'customer_name' => $request->customerName,
                        'description' => $description,
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Paystack unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();
        $url = $result['data']['authorization_url'] ?? null;

        if (($result['status'] ?? false) === true && is_string($url) && $url !== '') {
            return PaymentInitiation::redirect($request->reference, $url);
        }

        return PaymentInitiation::failed($result['message'] ?? 'Unknown Paystack error');
    }

    /**
     * HMAC-SHA512 over the raw body, in an `x-paystack-signature` header.
     *
     * There is no timestamp in the scheme, so replays cannot be rejected here.
     * That is safe only because CompleteTopup is idempotent — a replayed
     * success credits nothing the second time.
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Log::warning('Rejected a Paystack webhook: no secret key is configured');

            return false;
        }

        $signature = $headers['x-paystack-signature'] ?? '';

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $body, $this->webhookSecret), $signature);
    }

    /** @return string|null lowercase Paystack status, e.g. 'success' */
    public function checkStatus(string $reference): ?string
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->timeout(20)
                ->get(self::BASE_URL.'/transaction/verify/'.urlencode($reference));
        } catch (ConnectionException $e) {
            Log::warning('Paystack status check failed', ['error' => $e->getMessage()]);

            return null;
        }

        $status = $response->json('data.status');

        return is_string($status) ? strtolower($status) : null;
    }

    /** Kobo, pesewas or cents — every Paystack currency has a minor unit. */
    private function minorUnits(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
