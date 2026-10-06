<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;

/**
 * AnyPay (anypay.io) through its payment form, "SCI".
 *
 * The customer is sent to AnyPay's hosted page — cards and crypto — by a link
 * carrying the order and a signature, so nothing is called server-side to
 * start a payment and the link can be handed over in a chat. The signature is
 * the SHA-256 of the order fields and the project's secret key, joined by ":".
 *
 * AnyPay then calls the project's notification URL with the outcome, signed the
 * same way over (currency, amount, pay_id, merchant_id, status, secret). That
 * URL is one per AnyPay project, not per payment, so the reseller pastes ours
 * into their AnyPay project once.
 *
 * Two credentials, both from the AnyPay project: the project id (stored as the
 * API key) and the project's secret key (stored as the webhook secret, because
 * it is what verifies the notification — and it also signs the payment link).
 */
class AnypayClient implements PaymentGateway, WebhookVerifier
{
    private const MERCHANT_URL = 'https://anypay.io/merchant';

    /** Currencies AnyPay takes a payment in. Anything else is charged in dollars. */
    public const CURRENCIES = ['RUB', 'UAH', 'USD', 'EUR', 'BYN', 'KZT', 'UZS', 'AZN', 'AMD', 'KGS', 'TJS'];

    public function __construct(
        private string $projectId,
        private string $secretKey,
    ) {}

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        if ($this->projectId === '' || $this->secretKey === '') {
            return PaymentInitiation::failed('AnyPay is not set up: the project id and secret key are required.');
        }

        $currency = strtoupper(trim($request->currency));

        if (! in_array($currency, self::CURRENCIES, true)) {
            $converted = ExchangeRates::convert($request->amount, $currency, 'USD');

            if ($converted === null) {
                return PaymentInitiation::failed(
                    "No exchange rate for {$currency} to USD. Ask the shop owner to set one.",
                );
            }

            $amount = $converted;
            $currency = 'USD';
        } else {
            $amount = $request->amount;
        }

        $amount = number_format(round((float) $amount, 2), 2, '.', '');

        if ((float) $amount <= 0) {
            return PaymentInitiation::failed('That amount is too small to charge.');
        }

        $payId = $this->newPayId();
        $desc = mb_substr($description, 0, 150);

        // success_url and fail_url are part of the signature even though we
        // send neither (AnyPay only accepts URLs on the project's own domain),
        // so they go in as empty strings.
        $sign = $this->orderSignature($payId, $amount, $currency, $desc, '', '');

        return PaymentInitiation::redirect($payId, self::MERCHANT_URL.'?'.http_build_query([
            'merchant_id' => $this->projectId,
            'pay_id' => $payId,
            'amount' => $amount,
            'currency' => $currency,
            'desc' => $desc,
            'lang' => 'en',
            'sign' => $sign,
        ]));
    }

    /**
     * SHA-256 of merchant_id:pay_id:amount:currency:desc:success_url:fail_url:secret.
     */
    public function orderSignature(
        string $payId,
        string $amount,
        string $currency,
        string $desc,
        string $successUrl,
        string $failUrl,
    ): string {
        return hash('sha256', implode(':', [
            $this->projectId, $payId, $amount, $currency, $desc, $successUrl, $failUrl, $this->secretKey,
        ]));
    }

    /**
     * Check a notification: it is for this project, and its signature is
     * SHA-256 of currency:amount:pay_id:merchant_id:status:secret.
     *
     * The values are used exactly as received — re-formatting the amount
     * ("10" for "10.00") would change the hash. Refused when no secret is
     * saved: an unverifiable notification would let anyone credit a wallet.
     *
     * @param  array<string, mixed>  $params
     */
    public function verifyParams(array $params): bool
    {
        if ($this->secretKey === '' || $this->projectId === '') {
            Log::warning('Rejected an AnyPay notification: the project id or secret key is not configured');

            return false;
        }

        foreach (['currency', 'amount', 'pay_id', 'merchant_id', 'status', 'sign'] as $field) {
            if (! isset($params[$field]) || ! is_scalar($params[$field]) || (string) $params[$field] === '') {
                return false;
            }
        }

        if ((string) $params['merchant_id'] !== $this->projectId) {
            return false;
        }

        $expected = hash('sha256', implode(':', [
            $params['currency'], $params['amount'], $params['pay_id'],
            $params['merchant_id'], $params['status'], $this->secretKey,
        ]));

        return hash_equals($expected, strtolower((string) $params['sign']));
    }

    /**
     * For the raw-body shape of the contract. AnyPay sends form fields, and
     * possibly in the query string, so the controller prefers verifyParams().
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        parse_str($body, $params);

        return $this->verifyParams($params);
    }

    /**
     * Only a payment that is fully paid, and real, may credit a wallet.
     *
     * `partially-paid` is not enough, and `test=1` is AnyPay's own test mode —
     * money that never moved.
     *
     * @param  array<string, mixed>  $params
     */
    public static function isPaid(array $params): bool
    {
        return strtolower((string) ($params['status'] ?? '')) === 'paid'
            && (string) ($params['test'] ?? '0') !== '1';
    }

    /** AnyPay wants a number of at most 15 digits: milliseconds plus two random digits. */
    private function newPayId(): string
    {
        return (string) ((int) (microtime(true) * 1000) * 100 + random_int(0, 99));
    }
}
