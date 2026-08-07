<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * USDT / crypto via a Cryptomus hosted invoice.
 *
 * No KYB, which is why it is here: a reseller in a country Stripe will not
 * touch can still take crypto. Heleket runs the same API, so it subclasses
 * this rather than duplicating it.
 *
 * Both the request and the webhook are signed with
 * md5(base64(json_body) + api_key) — in the `sign` header on the way out, and
 * in the body on the way back.
 */
class CryptomusClient implements PaymentGateway, WebhookVerifier
{
    protected const INVOICE_URL = 'https://api.cryptomus.com/v1/payment';

    public function __construct(
        private string $apiKey,
        private string $merchantId,
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Resellers Hub'): PaymentInitiation
    {
        if ($this->apiKey === '' || $this->merchantId === '') {
            return PaymentInitiation::failed('This payment method is not configured.');
        }

        $payload = [
            'amount' => (string) round((float) $request->amount, 2),
            'currency' => strtoupper($request->currency),
            'order_id' => $request->reference,
            'url_callback' => $request->webhookUrl,
        ];

        // The body must be signed exactly as sent, so it is encoded once and
        // that same string goes on the wire.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'merchant' => $this->merchantId,
                'sign' => md5(base64_encode($body).$this->apiKey),
            ])
                ->timeout(30)
                ->withBody($body, 'application/json')
                ->post(static::INVOICE_URL);
        } catch (ConnectionException $e) {
            Log::warning(static::class.' unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();

        // state 0 with a url is the only success shape.
        if (is_array($result) && (int) ($result['state'] ?? 1) === 0 && ! empty($result['result']['url'])) {
            return PaymentInitiation::redirect(
                reference: (string) ($result['result']['uuid'] ?? $request->reference),
                url: (string) $result['result']['url'],
            );
        }

        return PaymentInitiation::failed(
            $result['message'] ?? 'Could not start the payment.',
        );
    }

    /**
     * Recompute the signature over the body with `sign` removed.
     *
     * Fails closed when no key is configured. The old implementation returned
     * true in that case, which let anyone who found the URL confirm their own
     * payment.
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->apiKey === '') {
            Log::warning('Rejected a '.static::class.' callback: no API key is configured');

            return false;
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            return false;
        }

        $signature = (string) ($data['sign'] ?? '');

        if ($signature === '') {
            return false;
        }

        unset($data['sign']);

        $expected = md5(base64_encode(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ).$this->apiKey);

        return hash_equals($expected, $signature);
    }

    /** Cryptomus's terminal paid states. "paid_over" is an overpayment. */
    public static function isPaidStatus(?string $status): bool
    {
        return in_array($status, ['paid', 'paid_over', 'finished'], true);
    }
}
