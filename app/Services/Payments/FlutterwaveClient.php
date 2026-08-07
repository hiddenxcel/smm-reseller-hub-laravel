<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cards and African mobile money via a Flutterwave hosted payment link.
 *
 * initiate() creates the link and returns it; Flutterwave POSTs a webhook when
 * the payer finishes.
 *
 * Its webhook auth is unusual: rather than signing the body, Flutterwave
 * echoes back the secret hash the reseller configured, in a `verif-hash`
 * header. That makes it a shared secret in transit, so the comparison below is
 * constant-time and an empty secret is refused outright.
 */
class FlutterwaveClient implements PaymentGateway, WebhookVerifier
{
    private const PAYMENTS_URL = 'https://api.flutterwave.com/v3/payments';

    public function __construct(
        private string $apiKey,
        private string $secretHash,
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post(self::PAYMENTS_URL, [
                    // Our own reference: it comes back on the webhook as
                    // txRef, which is how the payment is found again.
                    'tx_ref' => $request->reference,
                    'amount' => $request->amount,
                    'currency' => strtoupper($request->currency),
                    'redirect_url' => $request->webhookUrl,
                    'customer' => [
                        'name' => $request->customerName,
                        'phonenumber' => $request->phone,
                        // Flutterwave rejects a customer with no email, and a
                        // chat customer has never given one.
                        'email' => $this->placeholderEmail($request->reference),
                    ],
                    'customizations' => ['title' => $description],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Flutterwave unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();
        $link = $result['data']['link'] ?? null;

        if (($result['status'] ?? null) === 'success' && is_string($link) && $link !== '') {
            return PaymentInitiation::redirect($request->reference, $link);
        }

        return PaymentInitiation::failed($result['message'] ?? 'Unknown Flutterwave error');
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->secretHash === '') {
            Log::warning('Rejected a Flutterwave webhook: no secret hash is configured');

            return false;
        }

        $provided = $headers['verif-hash'] ?? '';

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($this->secretHash, $provided);
    }

    /**
     * A stable address derived from the reference. Deliberately unroutable:
     * it satisfies the field without inventing a customer's real address or
     * sending mail anywhere.
     */
    private function placeholderEmail(string $reference): string
    {
        return "{$reference}@no-reply.invalid";
    }
}
