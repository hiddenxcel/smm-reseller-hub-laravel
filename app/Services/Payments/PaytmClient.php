<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Paytm Payment Gateway (India): UPI, cards, net banking and wallets, in rupees.
 *
 * Starting a payment is two steps. Our server asks Paytm to initiate the
 * transaction (a signed, server-to-server call) and gets a token back; then
 * the customer's browser has to POST that token to Paytm's payment page. A
 * chat can only hand over a link, so the link points at a page of ours that
 * submits the form (PaymentFormController), as PayU's does.
 *
 * Credentials: the merchant id, the merchant key (which signs every request and
 * is what makes a callback believable), and optionally "test" for Paytm's
 * sandbox.
 *
 * A payment is credited only when Paytm's callback carries a checksum that
 * verifies under the merchant key and says TXN_SUCCESS.
 */
class PaytmClient implements PaymentGateway, StatusCheckable, WebhookVerifier
{
    private const LIVE_HOST = 'https://secure.paytmpayments.com';

    private const TEST_HOST = 'https://securestage.paytmpayments.com';

    /** The form page will only ever submit to a payment page on one of these. */
    public const PAYMENT_PAGE_PREFIXES = [
        self::LIVE_HOST.'/theia/api/v1/showPaymentPage?',
        self::TEST_HOST.'/theia/api/v1/showPaymentPage?',
    ];

    public function __construct(
        private string $merchantId,
        private string $merchantKey,
        private string $mode = '',
    ) {}

    private function isTest(): bool
    {
        return strtolower(trim($this->mode)) === 'test';
    }

    private function host(): string
    {
        return $this->isTest() ? self::TEST_HOST : self::LIVE_HOST;
    }

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        if ($this->merchantId === '' || $this->merchantKey === '') {
            return PaymentInitiation::failed('Paytm is not set up: the merchant id and merchant key are required.');
        }

        $converted = ExchangeRates::convert($request->amount, $request->currency, 'INR');

        if ($converted === null) {
            return PaymentInitiation::failed(
                "No exchange rate for {$request->currency} to INR. Ask the shop owner to set one.",
            );
        }

        $amount = number_format(round((float) $converted, 2), 2, '.', '');

        if ((float) $amount < 1) {
            return PaymentInitiation::failed('That amount is too small to charge.');
        }

        // Paytm takes letters, digits and a few symbols; our own reference is
        // not guaranteed to fit, so Paytm gets its own, remembered as the
        // payment's gateway reference.
        $orderId = 'tu'.Str::lower(Str::random(20));
        $digits = preg_replace('/\D+/', '', $request->phone) ?? '';

        $body = [
            'requestType' => 'Payment',
            'mid' => $this->merchantId,
            'websiteName' => $this->isTest() ? 'WEBSTAGING' : 'DEFAULT',
            'orderId' => $orderId,
            'callbackUrl' => route('webhooks.payment.return', 'paytm'),
            'txnAmount' => ['value' => $amount, 'currency' => 'INR'],
            'userInfo' => array_filter([
                'custId' => 'cust_'.Str::lower(Str::random(10)),
                'mobile' => substr($digits, -10) ?: null,
            ]),
        ];

        // Signed over the exact string that is sent: re-encoding it on the way
        // out would change the bytes and invalidate the signature.
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payload = '{"body":'.$json.',"head":{"signature":"'.PaytmChecksum::generate($json, $this->merchantKey).'"}}';

        try {
            $response = Http::withBody($payload, 'application/json')
                ->acceptJson()
                ->timeout(30)
                ->post($this->host()."/theia/api/v1/initiateTransaction?mid={$this->merchantId}&orderId={$orderId}");
        } catch (ConnectionException $e) {
            Log::warning('Paytm unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $token = $response->json('body.txnToken');

        if ($response->failed() || ! is_string($token) || $token === '') {
            return PaymentInitiation::failed(
                (string) ($response->json('body.resultInfo.resultMsg') ?: 'Unable to start the Paytm checkout.'),
            );
        }

        $link = URL::temporarySignedRoute('payment.form', now()->addHours(3), [
            'd' => Crypt::encryptString(json_encode([
                'action' => $this->host()."/theia/api/v1/showPaymentPage?mid={$this->merchantId}&orderId={$orderId}",
                'fields' => ['mid' => $this->merchantId, 'orderId' => $orderId, 'txnToken' => $token],
            ], JSON_THROW_ON_ERROR)),
        ]);

        return PaymentInitiation::redirect($orderId, $link);
    }

    /**
     * A callback is genuine when its CHECKSUMHASH verifies, over the other
     * fields, under the merchant key.
     *
     * @param  array<string, mixed>  $params
     */
    public function verifyParams(array $params): bool
    {
        if ($this->merchantKey === '') {
            Log::warning('Rejected a Paytm callback: the merchant key is not configured');

            return false;
        }

        $checksum = $params['CHECKSUMHASH'] ?? null;

        if (! is_string($checksum) || $checksum === '') {
            return false;
        }

        return PaytmChecksum::verify($params, $this->merchantKey, $checksum);
    }

    /** @param  array<string, string>  $headers */
    public function verifyWebhook(string $body, array $headers): bool
    {
        parse_str($body, $params);

        return $this->verifyParams($params);
    }

    /** Only a callback that says TXN_SUCCESS may credit a wallet. */
    public static function isPaid(array $params): bool
    {
        return strtoupper((string) ($params['STATUS'] ?? '')) === 'TXN_SUCCESS';
    }

    /**
     * Ask Paytm for the order's status (Transaction Status API).
     *
     * @return string|null completed | failed | pending, or null when Paytm could not be reached
     */
    public function checkStatus(string $reference): ?string
    {
        $json = json_encode(['mid' => $this->merchantId, 'orderId' => $reference], JSON_THROW_ON_ERROR);
        $payload = '{"body":'.$json.',"head":{"signature":"'.PaytmChecksum::generate($json, $this->merchantKey).'"}}';

        try {
            $response = Http::withBody($payload, 'application/json')
                ->acceptJson()
                ->timeout(30)
                ->post($this->host().'/v3/order/status');
        } catch (ConnectionException) {
            return null;
        }

        $status = $response->json('body.resultInfo.resultStatus');

        if (! is_string($status)) {
            return $response->successful() ? 'pending' : null;
        }

        return match (strtoupper($status)) {
            'TXN_SUCCESS' => 'completed',
            'TXN_FAILURE' => 'failed',
            default => 'pending',
        };
    }
}
