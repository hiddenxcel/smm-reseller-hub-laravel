<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FimiPay Merchant API v1, one market at a time.
 *
 * Every market — Nigeria, Ghana, Cameroon, South Africa and international USD
 * cards — goes through FimiPay's hosted checkout, so the flow is always the
 * same: create an order, send the customer to the page FimiPay returns, and wait
 * for the signed webhook. The customer arriving back on the redirect URL is
 * never treated as proof of payment; only the webhook (or an order-status query)
 * is.
 *
 * A market is a separate gateway for the same reason Snippe's are: what differs
 * between them is the currency, the phone country code and the payment method,
 * and all three are decided by which one the customer picked, not guessed from
 * their number. They share this one class.
 *
 * Two credentials: the secret key (sk_live_… / sk_test_…) that calls the API,
 * and the webhook secret the notifications are signed with.
 */
class FimipayClient implements PaymentGateway, StatusCheckable, WebhookVerifier
{
    private const BASE_URL = 'https://fimipay.com/api/v1';

    /**
     * The markets: gateway code => what FimiPay needs for it.
     *
     * `dial` is the country code a local number is completed with; null means
     * any international number is accepted. `method` is FimiPay's own name for
     * how the customer pays on its checkout page.
     */
    public const MARKETS = [
        'fimipay_ng' => ['currency' => 'NGN', 'dial' => '234', 'method' => 'bank'],
        'fimipay_gh' => ['currency' => 'GHS', 'dial' => '233', 'method' => 'mobile'],
        'fimipay_cm' => ['currency' => 'XAF', 'dial' => '237', 'method' => 'mobile'],
        'fimipay_za' => ['currency' => 'ZAR', 'dial' => '27', 'method' => 'card'],
        'fimipay_usd' => ['currency' => 'USD', 'dial' => null, 'method' => 'card'],
    ];

    /** @param  string  $code  one of the keys of MARKETS */
    public function __construct(
        private string $secretKey,
        private string $webhookSecret,
        private string $code = 'fimipay_usd',
    ) {}

    /** @return array<int, string> every FimiPay gateway code, one per market */
    public static function codes(): array
    {
        return array_keys(self::MARKETS);
    }

    public static function isFimipay(?string $code): bool
    {
        return isset(self::MARKETS[(string) $code]);
    }

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        $market = self::MARKETS[$this->code] ?? null;

        if ($market === null) {
            return PaymentInitiation::failed('This FimiPay payment method is not set up correctly.');
        }

        $phone = $this->normalisePhone($request->phone, $market['dial']);

        if ($phone === null) {
            return PaymentInitiation::failed('A valid phone number is required for this payment method.');
        }

        $converted = ExchangeRates::convert($request->amount, $request->currency, $market['currency']);

        if ($converted === null) {
            return PaymentInitiation::failed(
                "No exchange rate for {$request->currency} to {$market['currency']}. Ask the shop owner to set one.",
            );
        }

        // Dollars keep their cents; every other market takes whole units.
        $amount = $market['currency'] === 'USD'
            ? round((float) $converted, 2)
            : ExchangeRates::wholeUnits($converted);

        if ($amount <= 0) {
            return PaymentInitiation::failed('That amount is too small to charge.');
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->timeout(60)
                ->post(self::BASE_URL.'/payment/create_order', [
                    // Our own reference, so the webhook's `order_id` is
                    // something we can look up directly.
                    'order_id' => $request->reference,
                    'buyer_phone' => $phone,
                    'buyer_name' => Str::ascii(trim($request->customerName)) ?: 'Customer',
                    'buyer_email' => $this->email($request),
                    'amount' => $amount,
                    'currency' => $market['currency'],
                    'payment_method' => $market['method'],
                    'redirect_url' => route('payment.thanks'),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('FimiPay unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $data = $response->json() ?? [];

        if (
            $response->failed()
            || ($data['status'] ?? '') === 'error'
            || ! isset($data['data']['order_id'], $data['data']['payment_gateway_url'])
        ) {
            return PaymentInitiation::failed($data['message'] ?? 'Unable to start FimiPay checkout.');
        }

        return PaymentInitiation::redirect(
            (string) $data['data']['order_id'],
            (string) $data['data']['payment_gateway_url'],
        );
    }

    /** What FimiPay says the order's state is, in the words this app uses. */
    public function checkStatus(string $reference): ?string
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->timeout(30)
                ->post(self::BASE_URL.'/payment/order_status', ['order_id' => $reference]);
        } catch (ConnectionException) {
            return null;
        }

        $status = $response->json('data.payment_status');

        return is_string($status) ? self::normaliseStatus($status) : null;
    }

    /**
     * HMAC-SHA256 of the raw body, in the X-Fimipay-Signature header.
     *
     * Over the exact bytes received: re-encoding the JSON would reorder keys and
     * change the signature. Refused outright when no webhook secret is saved —
     * an unverifiable webhook is an open door to credit a wallet.
     *
     * @param  array<string, string>  $headers  lower-cased header names
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Log::warning('Rejected a FimiPay webhook: no webhook secret is configured');

            return false;
        }

        $signature = array_change_key_case($headers, CASE_LOWER)['x-fimipay-signature'] ?? '';

        return $signature !== ''
            && hash_equals(hash_hmac('sha256', $body, $this->webhookSecret), $signature);
    }

    /**
     * FimiPay's vocabulary, mapped to completed / failed / pending.
     *
     * Anything unrecognised is pending, which is the safe way to be wrong: a
     * payment that is not known to have succeeded must not credit a wallet.
     */
    public static function normaliseStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'SUCCESS', 'COMPLETED', 'SUCCESSFUL', 'PAID' => 'completed',
            'REJECTED', 'CANCELLED', 'CANCELED', 'USERCANCELLED', 'FAILED', 'DECLINED', 'EXPIRED' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Digits only, the local leading 0 swapped for the market's dial code, and
     * the dial code added when missing. USD accepts any international number,
     * so it is only reduced to digits.
     */
    private function normalisePhone(string $phone, ?string $dial): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($dial !== null) {
            if (str_starts_with($digits, '00'.$dial)) {
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, '0')) {
                $digits = $dial.substr($digits, 1);
            } elseif (! str_starts_with($digits, $dial)) {
                $digits = $dial.$digits;
            }
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * FimiPay wants an address and a chat customer has none. One that parses,
     * unique to the payment, on this site's own domain.
     */
    private function email(PaymentRequest $request): string
    {
        if ($request->customerEmail !== '') {
            return $request->customerEmail;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com';

        return 'customer+'.Str::lower(Str::limit(preg_replace('/\W/', '', $request->reference) ?? '', 24, '')).'@'.$host;
    }
}
