<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * USDT via the Binance Pay merchant API.
 *
 * Both the outgoing request signature and the incoming webhook signature are
 * HMAC-SHA512, uppercased, over "{timestamp}\n{nonce}\n{body}\n".
 */
class BinancePayClient implements PaymentGateway, WebhookVerifier
{
    private const ORDER_URL = 'https://bpay.binanceapi.com/binancepay/openapi/v3/order';

    public function __construct(
        private string $apiKey,
        private string $apiSecret,
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Resellers Hub'): PaymentInitiation
    {
        $body = json_encode([
            'env' => ['terminalType' => 'WEB'],
            'merchantTradeNo' => $request->reference,
            'orderAmount' => (float) $request->amount,
            'currency' => $request->currency !== '' ? $request->currency : 'USDT',
            'goods' => [
                'goodsType' => '02',
                'goodsCategory' => '6000',
                'referenceGoodsId' => $request->reference,
                'goodsName' => $description,
            ],
            'webhookUrl' => $request->webhookUrl,
        ]);

        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));

        try {
            // The signature covers the exact body bytes, so it is sent with
            // withBody() rather than an array Laravel would re-encode.
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'BinancePay-Timestamp' => $timestamp,
                'BinancePay-Nonce' => $nonce,
                'BinancePay-Certificate-SN' => $this->apiKey,
                'BinancePay-Signature' => $this->sign($timestamp, $nonce, $body),
            ])
                ->timeout(30)
                ->withBody($body, 'application/json')
                ->post(self::ORDER_URL);
        } catch (ConnectionException $e) {
            Log::warning('Binance Pay unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();

        if (($result['status'] ?? '') === 'SUCCESS' && isset($result['data']['universalUrl'])) {
            return PaymentInitiation::redirect(
                reference: (string) ($result['data']['prepayId'] ?? $request->reference),
                url: (string) ($result['data']['checkoutUrl'] ?? $result['data']['universalUrl']),
            );
        }

        return PaymentInitiation::failed($result['errorMessage'] ?? 'Unknown Binance Pay error');
    }

    /**
     * @param  array{signature?: string, timestamp?: string, nonce?: string}  $headers
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->apiSecret === '') {
            Log::warning('Rejected a Binance Pay webhook: no API secret is configured');

            return false;
        }

        $signature = $headers['signature'] ?? '';
        $timestamp = $headers['timestamp'] ?? '';
        $nonce = $headers['nonce'] ?? '';

        if ($signature === '' || $timestamp === '' || $nonce === '') {
            return false;
        }

        return hash_equals($this->sign($timestamp, $nonce, $body), $signature);
    }

    /** bizStatus PAY_SUCCESS means the payment cleared. */
    public static function isPaidStatus(?string $bizStatus): bool
    {
        return $bizStatus === 'PAY_SUCCESS';
    }

    private function sign(string $timestamp, string $nonce, string $body): string
    {
        return strtoupper(hash_hmac('sha512', "{$timestamp}\n{$nonce}\n{$body}\n", $this->apiSecret));
    }
}
