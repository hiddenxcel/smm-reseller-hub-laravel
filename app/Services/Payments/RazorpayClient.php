<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cards, UPI, netbanking and wallets across India, via a Razorpay payment link.
 *
 * A payment link rather than an order: Razorpay's Orders API returns an id to
 * be handed to their browser checkout widget, which a WhatsApp customer has no
 * way to open. Payment Links return a hosted short_url that can simply be sent
 * to them, at the cost of the customer seeing Razorpay's page rather than the
 * reseller's.
 *
 * Credentials are the key id and key secret, used as HTTP basic auth. The
 * webhook secret is a third value the reseller sets when creating the webhook
 * in Razorpay's dashboard, so this gateway uses all three credential slots.
 */
class RazorpayClient implements PaymentGateway, StatusCheckable, WebhookVerifier
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function __construct(
        private string $keyId,
        private string $keySecret,
        private string $webhookSecret = '',
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        try {
            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->timeout(30)
                ->post(self::BASE_URL.'/payment_links', [
                    'amount' => $this->minorUnits($request->amount),
                    'currency' => strtoupper($request->currency),
                    // Razorpay caps this at 2048 characters and rejects a blank.
                    'description' => mb_substr($description, 0, 2048),
                    // Our reference, echoed on the webhook under
                    // payload.payment_link.entity.reference_id.
                    'reference_id' => $request->reference,
                    'callback_url' => $request->webhookUrl,
                    'callback_method' => 'get',
                    'notes' => ['reference' => $request->reference],
                    'customer' => [
                        'name' => $request->customerName,
                        'contact' => $request->phone,
                    ],
                    // Razorpay would otherwise try to email and SMS the link
                    // itself; the bot is what delivers it.
                    'notify' => ['sms' => false, 'email' => false],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Razorpay unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();
        $url = $result['short_url'] ?? null;

        if ($response->successful() && is_string($url) && $url !== '') {
            return PaymentInitiation::redirect($request->reference, $url);
        }

        return PaymentInitiation::failed(
            $result['error']['description'] ?? 'Unknown Razorpay error',
        );
    }

    /**
     * HMAC-SHA256 over the raw body, in an `x-razorpay-signature` header.
     *
     * Signed with the webhook secret from the dashboard, not with the API key
     * secret — a reseller who fills in the wrong one gets rejected webhooks
     * rather than a security hole.
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Log::warning('Rejected a Razorpay webhook: no webhook secret is configured');

            return false;
        }

        $signature = $headers['x-razorpay-signature'] ?? '';

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $this->webhookSecret), $signature);
    }

    /**
     * The status of a payment link, found by the reference we set on it.
     *
     * Razorpay has no lookup by reference_id alone, so the links are filtered
     * client-side. 'paid' is the status that means the money arrived.
     */
    public function checkStatus(string $reference): ?string
    {
        try {
            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->timeout(20)
                ->get(self::BASE_URL.'/payment_links', ['reference_id' => $reference]);
        } catch (ConnectionException $e) {
            Log::warning('Razorpay status check failed', ['error' => $e->getMessage()]);

            return null;
        }

        $status = $response->json('payment_links.0.status');

        return is_string($status) ? strtolower($status) : null;
    }

    /** Paise for INR, and the equivalent minor unit for every other currency. */
    private function minorUnits(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
