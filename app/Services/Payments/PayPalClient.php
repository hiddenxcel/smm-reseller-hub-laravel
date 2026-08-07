<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal, via a v2 Checkout order the payer approves on PayPal's site.
 *
 * Two calls to start one payment: an OAuth token, then the order. The token is
 * held only for the duration of the request — caching it across requests would
 * mean holding one reseller's credentials in memory for another's.
 *
 * Verification is a round trip rather than a local HMAC: PayPal signs with a
 * rotating certificate, so the safe way to check a webhook is to post it back
 * and let PayPal answer. That call needs the webhook's own id, which is why
 * this gateway stores three credentials.
 */
class PayPalClient implements WebhookVerifier
{
    private const BASE_URL = 'https://api-m.paypal.com';

    /** The only event that means money actually moved. */
    public const COMPLETED_EVENT = 'PAYMENT.CAPTURE.COMPLETED';

    public function __construct(
        private string $clientId,
        private string $secret,
        private string $webhookId = '',
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        $token = $this->accessToken();

        if ($token === null) {
            return PaymentInitiation::failed('Could not authenticate with PayPal');
        }

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                // PayPal deduplicates on this, so a retry cannot create a
                // second order for the same top-up.
                ->withHeaders(['PayPal-Request-Id' => $request->reference])
                ->post(self::BASE_URL.'/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        // Both carry our reference: custom_id survives into the
                        // capture webhook, reference_id into the order.
                        'reference_id' => $request->reference,
                        'custom_id' => $request->reference,
                        'description' => $description,
                        'amount' => [
                            'currency_code' => strtoupper($request->currency),
                            'value' => number_format((float) $request->amount, 2, '.', ''),
                        ],
                    ]],
                    'application_context' => [
                        'return_url' => $request->webhookUrl,
                        'cancel_url' => $request->webhookUrl,
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('PayPal unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();
        $approve = $this->approvalUrl($result['links'] ?? []);

        if ($response->successful() && $approve !== null) {
            return PaymentInitiation::redirect($request->reference, $approve);
        }

        return PaymentInitiation::failed($result['message'] ?? 'Unknown PayPal error');
    }

    /**
     * Ask PayPal whether they sent this.
     *
     * Fails closed on anything short of an explicit SUCCESS: a missing header,
     * an unreachable API, or a malformed body all mean we cannot show the
     * webhook is genuine, and an unverified webhook credits nothing.
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->webhookId === '') {
            Log::warning('Rejected a PayPal webhook: no webhook id is configured');

            return false;
        }

        $required = [
            'transmission_id' => 'paypal-transmission-id',
            'transmission_time' => 'paypal-transmission-time',
            'cert_url' => 'paypal-cert-url',
            'auth_algo' => 'paypal-auth-algo',
            'transmission_sig' => 'paypal-transmission-sig',
        ];

        $payload = ['webhook_id' => $this->webhookId];

        foreach ($required as $field => $header) {
            $value = $headers[$header] ?? '';

            if (! is_string($value) || $value === '') {
                return false;
            }

            $payload[$field] = $value;
        }

        $event = json_decode($body, true);

        if (! is_array($event)) {
            return false;
        }

        $payload['webhook_event'] = $event;

        $token = $this->accessToken();

        if ($token === null) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post(self::BASE_URL.'/v1/notifications/verify-webhook-signature', $payload);
        } catch (ConnectionException $e) {
            Log::warning('PayPal verification unreachable', ['error' => $e->getMessage()]);

            return false;
        }

        return $response->json('verification_status') === 'SUCCESS';
    }

    private function accessToken(): ?string
    {
        try {
            $response = Http::withBasicAuth($this->clientId, $this->secret)
                ->asForm()
                ->timeout(20)
                ->post(self::BASE_URL.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $e) {
            Log::warning('PayPal token request failed', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @param array<int, array{rel?: string, href?: string}> $links */
    private function approvalUrl(array $links): ?string
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? '') === 'approve' && filled($link['href'] ?? null)) {
                return (string) $link['href'];
            }
        }

        return null;
    }
}
