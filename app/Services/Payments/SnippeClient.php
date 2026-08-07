<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tanzania mobile money (M-Pesa / Tigo / Airtel / Halopesa) via USSD push.
 *
 * initiate() triggers a prompt on the payer's phone and returns a reference;
 * confirmation arrives later on the webhook. There is no redirect URL — the
 * customer approves on the handset.
 */
class SnippeClient implements PaymentGateway, StatusCheckable, WebhookVerifier
{
    private const PAYMENTS_URL = 'https://api.snippe.sh/v1/payments';

    /** Reject webhooks whose timestamp is further than this from now. */
    private const REPLAY_WINDOW_SECONDS = 300;

    public function __construct(
        private string $apiKey,
        private string $webhookSecret,
    ) {}

    /** The description goes unused: a USSD prompt shows the amount, not a label. */
    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders(['Idempotency-Key' => $request->reference])
                ->timeout(30)
                ->post(self::PAYMENTS_URL, [
                    'payment_type' => 'mobile',
                    'phone_number' => $request->phone,
                    'details' => [
                        // Snippe takes whole units, not cents.
                        'amount' => (int) round((float) $request->amount),
                        'currency' => $request->currency,
                    ],
                    'customer' => [
                        'firstname' => $request->customerName,
                        'lastname' => '',
                        'email' => '',
                    ],
                    'webhook_url' => $request->webhookUrl,
                    'metadata' => ['order_id' => $request->reference],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Snippe unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();

        if (($result['status'] ?? null) === 'success' && isset($result['data']['reference'])) {
            return PaymentInitiation::pushed((string) $result['data']['reference']);
        }

        return PaymentInitiation::failed($result['message'] ?? 'Unknown Snippe error');
    }

    public function checkStatus(string $reference): ?string
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->get(self::PAYMENTS_URL.'/'.urlencode($reference));
        } catch (ConnectionException) {
            return null;
        }

        return $response->json('data.status');
    }

    /**
     * HMAC-SHA256 over "{timestamp}.{body}", within a 5-minute window.
     *
     * @param  array{signature?: string, timestamp?: string}  $headers
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Log::warning('Rejected a Snippe webhook: no webhook secret is configured');

            return false;
        }

        $signature = $headers['signature'] ?? '';
        $timestamp = $headers['timestamp'] ?? '';

        if ($signature === '' || $timestamp === '') {
            return false;
        }

        // Replay protection: an old-but-validly-signed body must not re-credit.
        if (abs(time() - (int) $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $this->webhookSecret);

        return hash_equals($expected, $signature);
    }
}
