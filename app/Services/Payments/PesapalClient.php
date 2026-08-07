<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * East African cards and mobile money via Pesapal API 3.0.
 *
 * Three things make this the odd one out:
 *
 *   1. Every call needs a bearer token that expires after five minutes, so one
 *      is fetched per operation rather than held.
 *   2. An order must quote the id of a registered IPN URL. That registration
 *      happens once and its id is stored as the gateway's third credential.
 *   3. The IPN carries no payment status — Pesapal withholds it deliberately.
 *      Confirming a payment means calling back for the status, which is what
 *      `checkStatus` is for and why this gateway is marked `verify`.
 */
class PesapalClient implements WebhookVerifier
{
    private const BASE_URL = 'https://pay.pesapal.com/v3';

    public function __construct(
        private string $consumerKey,
        private string $consumerSecret,
        private string $ipnId = '',
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        if ($this->ipnId === '') {
            return PaymentInitiation::failed('Pesapal IPN is not registered for this store');
        }

        $token = $this->accessToken();

        if ($token === null) {
            return PaymentInitiation::failed('Could not authenticate with Pesapal');
        }

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->post(self::BASE_URL.'/api/Transactions/SubmitOrderRequest', [
                    // Our reference comes back as OrderMerchantReference.
                    'id' => $request->reference,
                    'currency' => strtoupper($request->currency),
                    'amount' => (float) $request->amount,
                    // Pesapal caps this at 100 characters.
                    'description' => mb_substr($description, 0, 100),
                    'callback_url' => $request->webhookUrl,
                    'notification_id' => $this->ipnId,
                    'billing_address' => [
                        'phone_number' => $request->phone,
                        'first_name' => $request->customerName,
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Pesapal unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();
        $url = $result['redirect_url'] ?? null;

        if (is_string($url) && $url !== '') {
            return PaymentInitiation::redirect($request->reference, $url);
        }

        return PaymentInitiation::failed(
            $result['error']['message'] ?? $result['message'] ?? 'Unknown Pesapal error',
        );
    }

    /**
     * Register a notification URL and return the id every order must quote.
     *
     * Called from the dashboard when a reseller connects Pesapal, not on the
     * payment path — the id is issued once and kept.
     */
    public function registerIpn(string $url): ?string
    {
        $token = $this->accessToken();

        if ($token === null) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->post(self::BASE_URL.'/api/URLSetup/RegisterIPN', [
                    'url' => $url,
                    'ipn_notification_type' => 'POST',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Pesapal IPN registration failed', ['error' => $e->getMessage()]);

            return null;
        }

        $id = $response->json('ipn_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The real answer to "was this paid?".
     *
     * Pesapal's own words: "The callback URL and the IPN calls will NOT have
     * the status of the payment for security reasons." So the IPN is only a
     * nudge, and this is what decides.
     */
    public function checkStatus(string $orderTrackingId): ?string
    {
        $token = $this->accessToken();

        if ($token === null) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->get(self::BASE_URL.'/api/Transactions/GetTransactionStatus', [
                    'orderTrackingId' => $orderTrackingId,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Pesapal status check failed', ['error' => $e->getMessage()]);

            return null;
        }

        $status = $response->json('payment_status_description');

        return is_string($status) ? strtolower($status) : null;
    }

    /**
     * There is no signature to check — Pesapal authenticates the *result*, not
     * the notification. Anyone can POST to the IPN URL, so a webhook alone
     * proves nothing and this always returns false: `PaymentWebhookController`
     * routes `verify` gateways through checkStatus() instead.
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        return false;
    }

    private function accessToken(): ?string
    {
        try {
            $response = Http::timeout(20)
                ->post(self::BASE_URL.'/api/Auth/RequestToken', [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Pesapal token request failed', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $response->json('token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
