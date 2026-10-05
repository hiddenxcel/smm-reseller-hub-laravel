<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * USDT / crypto via a NOWPayments hosted invoice.
 *
 * initiate() creates the invoice and returns its checkout URL; the payer pays
 * there and NOWPayments POSTs an IPN callback back to us.
 */
class NowPaymentsClient implements PaymentGateway, StatusCheckable, WebhookVerifier
{
    private const INVOICE_URL = 'https://api.nowpayments.io/v1/invoice';

    private const STATUS_URL = 'https://api.nowpayments.io/v1/payment/';

    public function __construct(
        private string $apiKey,
        private string $ipnSecret,
        private string $payCurrency = 'usdttrc20',
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Auto Resellers Hub'): PaymentInitiation
    {
        try {
            $response = Http::withHeaders(['x-api-key' => $this->apiKey])
                ->timeout(30)
                ->post(self::INVOICE_URL, [
                    'price_amount' => (float) $request->amount,
                    'price_currency' => strtolower($request->currency),
                    'pay_currency' => $this->payCurrency,
                    'order_id' => $request->reference,
                    'order_description' => $description,
                    'ipn_callback_url' => $request->webhookUrl,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('NOWPayments unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();

        if (isset($result['invoice_url'])) {
            return PaymentInitiation::redirect(
                reference: (string) ($result['id'] ?? $request->reference),
                url: (string) $result['invoice_url'],
            );
        }

        return PaymentInitiation::failed($result['message'] ?? 'Unknown NOWPayments error');
    }

    public function checkStatus(string $paymentId): ?string
    {
        try {
            $response = Http::withHeaders(['x-api-key' => $this->apiKey])
                ->timeout(15)
                ->get(self::STATUS_URL.urlencode($paymentId));
        } catch (ConnectionException) {
            return null;
        }

        return $response->json('payment_status');
    }

    /**
     * HMAC-SHA512 over the IPN body with keys sorted alphabetically at every
     * level. NOWPayments computes it over its own canonical JSON, so the
     * sorting and JSON_UNESCAPED_SLASHES must match byte-for-byte or every
     * callback fails.
     *
     * @param  array{signature?: string}  $headers
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->ipnSecret === '') {
            Log::warning('Rejected a NOWPayments IPN: no IPN secret is configured');

            return false;
        }

        $signature = $headers['signature'] ?? '';
        if ($signature === '') {
            return false;
        }

        $data = json_decode($body, true);
        if (! is_array($data)) {
            return false;
        }

        $expected = hash_hmac(
            'sha512',
            json_encode($this->sortRecursive($data), JSON_UNESCAPED_SLASHES),
            $this->ipnSecret,
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Only `finished` means the money reached us.
     *
     * The lifecycle is waiting → confirming → confirmed → sending → finished.
     * `confirmed` sounds terminal but is not: the blockchain has confirmed the
     * customer's transfer, and NOWPayments has still to forward it. A payment
     * can be `confirmed` and then fail on the way. `partially_paid` means they
     * sent less than the invoice and is never a success.
     */
    public static function isPaidStatus(?string $status): bool
    {
        return $status === 'finished';
    }

    private function sortRecursive(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }

        return $data;
    }
}
