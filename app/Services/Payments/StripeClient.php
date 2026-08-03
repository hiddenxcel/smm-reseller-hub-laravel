<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cards via a Stripe Checkout Session.
 *
 * initiate() creates the session and returns its hosted URL; Stripe posts
 * `checkout.session.completed` back when the payer finishes.
 *
 * Stripe has no "charge this amount" call — a session is built from line
 * items, so the top-up becomes a one-off inline price rather than a product
 * in the reseller's catalogue.
 */
class StripeClient implements WebhookVerifier
{
    private const SESSIONS_URL = 'https://api.stripe.com/v1/checkout/sessions';

    /** Reject a signature whose timestamp is further than this from now. */
    private const REPLAY_WINDOW_SECONDS = 300;

    /**
     * Currencies Stripe treats as having no minor unit. Everything else is
     * charged in cents, and sending whole units would overcharge by 100x.
     */
    private const ZERO_DECIMAL = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    public function __construct(
        private string $apiKey,
        private string $webhookSecret,
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, '')
                ->asForm()
                ->timeout(30)
                // Stripe deduplicates on this, so a retried call cannot create
                // a second session for the same top-up.
                ->withHeaders(['Idempotency-Key' => $request->reference])
                ->post(self::SESSIONS_URL, [
                    'mode' => 'payment',
                    'success_url' => $request->webhookUrl,
                    'cancel_url' => $request->webhookUrl,
                    // Comes back on the webhook, which is how we find the
                    // payment this session belongs to.
                    'client_reference_id' => $request->reference,
                    'metadata[reference]' => $request->reference,
                    'line_items[0][quantity]' => 1,
                    'line_items[0][price_data][currency]' => strtolower($request->currency),
                    'line_items[0][price_data][unit_amount]' => $this->minorUnits(
                        $request->amount,
                        $request->currency,
                    ),
                    'line_items[0][price_data][product_data][name]' => $description,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Stripe unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();
        $url = $result['url'] ?? null;

        if ($response->successful() && is_string($url) && $url !== '') {
            return PaymentInitiation::redirect($request->reference, $url);
        }

        return PaymentInitiation::failed(
            $result['error']['message'] ?? 'Unknown Stripe error',
        );
    }

    /**
     * HMAC-SHA256 over "{timestamp}.{body}", carried in a header of
     * comma-separated pairs: `t=…,v1=…`.
     *
     * Only the v1 scheme is accepted — v0 is Stripe's test signature and must
     * never satisfy a live check.
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Log::warning('Rejected a Stripe webhook: no signing secret is configured');

            return false;
        }

        $header = $headers['stripe-signature'] ?? '';

        if ($header === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            // A header may carry several v1 signatures during a secret
            // rotation; the first that verifies is enough.
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? '';
        $signatures = $parts['v1'] ?? [];

        if ($timestamp === '' || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $this->webhookSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stripe charges in the currency's smallest unit — cents for most, whole
     * units for the handful that have no subdivision.
     */
    private function minorUnits(string $amount, string $currency): int
    {
        if (in_array(strtolower($currency), self::ZERO_DECIMAL, true)) {
            return (int) round((float) $amount);
        }

        return (int) round((float) $amount * 100);
    }
}
