<?php

namespace Tests\Unit;

use App\Services\Payments\BinancePayClient;
use App\Services\Payments\CryptomusClient;
use App\Services\Payments\HeleketClient;
use App\Services\Payments\NowPaymentsClient;
use App\Services\Payments\SnippeClient;
use Tests\TestCase;

/**
 * Webhook signature verification decides whether an unauthenticated HTTP
 * request can credit a wallet, so it is worth pinning hard.
 *
 * The old clients all returned true when no secret was configured. These
 * tests exist mainly to make sure that never comes back.
 */
class PaymentWebhookVerificationTest extends TestCase
{
    // ---- Snippe: HMAC-SHA256 over "{timestamp}.{body}" ------------------

    public function test_snippe_accepts_a_correctly_signed_webhook(): void
    {
        $secret = 'snippe-secret';
        $body = '{"status":"success","reference":"abc"}';
        $timestamp = (string) time();

        $client = new SnippeClient('api-key', $secret);

        $this->assertTrue($client->verifyWebhook($body, [
            'signature' => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
            'timestamp' => $timestamp,
        ]));
    }

    public function test_snippe_rejects_a_tampered_body(): void
    {
        $secret = 'snippe-secret';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.".'{"amount":10}', $secret);

        $client = new SnippeClient('api-key', $secret);

        $this->assertFalse($client->verifyWebhook('{"amount":10000}', [
            'signature' => $signature,
            'timestamp' => $timestamp,
        ]));
    }

    public function test_snippe_rejects_a_replayed_old_timestamp(): void
    {
        $secret = 'snippe-secret';
        $body = '{"status":"success"}';
        $timestamp = (string) (time() - 600); // outside the 5-minute window

        $client = new SnippeClient('api-key', $secret);

        $this->assertFalse($client->verifyWebhook($body, [
            'signature' => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
            'timestamp' => $timestamp,
        ]));
    }

    public function test_snippe_rejects_when_no_secret_is_configured(): void
    {
        // The regression that matters: an unconfigured gateway must not
        // accept unauthenticated payment confirmations.
        $client = new SnippeClient('api-key', '');

        $this->assertFalse($client->verifyWebhook('{"status":"success"}', [
            'signature' => 'anything',
            'timestamp' => (string) time(),
        ]));
    }

    public function test_snippe_rejects_a_missing_signature(): void
    {
        $client = new SnippeClient('api-key', 'snippe-secret');

        $this->assertFalse($client->verifyWebhook('{}', ['timestamp' => (string) time()]));
    }

    // ---- NOWPayments: HMAC-SHA512 over sorted JSON -----------------------

    public function test_nowpayments_accepts_a_correctly_signed_ipn(): void
    {
        $secret = 'ipn-secret';
        // Deliberately unsorted, and nested, to exercise the canonicalisation.
        $body = '{"payment_status":"finished","order_id":"REF1","outcome":{"currency":"usdt","amount":5}}';

        $sorted = $this->sortRecursive(json_decode($body, true));
        $signature = hash_hmac('sha512', json_encode($sorted, JSON_UNESCAPED_SLASHES), $secret);

        $client = new NowPaymentsClient('api-key', $secret);

        $this->assertTrue($client->verifyWebhook($body, ['signature' => $signature]));
    }

    public function test_nowpayments_rejects_a_tampered_amount(): void
    {
        $secret = 'ipn-secret';
        $original = '{"order_id":"REF1","pay_amount":5}';
        $signature = hash_hmac(
            'sha512',
            json_encode($this->sortRecursive(json_decode($original, true)), JSON_UNESCAPED_SLASHES),
            $secret,
        );

        $client = new NowPaymentsClient('api-key', $secret);

        $this->assertFalse($client->verifyWebhook('{"order_id":"REF1","pay_amount":5000}', [
            'signature' => $signature,
        ]));
    }

    public function test_nowpayments_rejects_when_no_secret_is_configured(): void
    {
        $client = new NowPaymentsClient('api-key', '');

        $this->assertFalse($client->verifyWebhook('{"payment_status":"finished"}', [
            'signature' => 'anything',
        ]));
    }

    public function test_nowpayments_rejects_a_non_json_body(): void
    {
        $client = new NowPaymentsClient('api-key', 'ipn-secret');

        $this->assertFalse($client->verifyWebhook('not json at all', ['signature' => 'x']));
    }

    /**
     * The lifecycle is waiting → confirming → confirmed → sending → finished,
     * and only the last means the money is ours. `confirmed` reads like an
     * ending but is the blockchain confirming the customer's transfer, before
     * NOWPayments has forwarded it — a payment can be confirmed and still fail.
     */
    public function test_nowpayments_paid_statuses(): void
    {
        $this->assertTrue(NowPaymentsClient::isPaidStatus('finished'));

        $this->assertFalse(NowPaymentsClient::isPaidStatus('confirmed'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus('sending'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus('confirming'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus('waiting'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus('partially_paid'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus('failed'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus(null));
    }

    // ---- Cryptomus / Heleket: md5(base64(json) + key), slashes escaped ----

    /**
     * The bug this pins: Cryptomus signs with PHP's json_encode defaults, so
     * "https://x" is signed as "https:\/\/x". Verifying with
     * JSON_UNESCAPED_SLASHES rejected every genuine callback, because every
     * callback carries url_callback.
     */
    public function test_cryptomus_accepts_a_callback_whose_body_contains_urls(): void
    {
        $key = 'payment-api-key';

        $data = [
            'type' => 'payment',
            'uuid' => 'abc-123',
            'order_id' => 'SUB-TEST',
            'status' => 'paid',
            'url_callback' => 'https://hub.test/webhooks/billing/cryptomus',
        ];

        // Signed the way the provider does it: slashes escaped.
        $data['sign'] = md5(base64_encode(json_encode(
            array_diff_key($data, ['sign' => null]),
            JSON_UNESCAPED_UNICODE,
        )).$key);

        $client = new CryptomusClient($key, 'merchant-uuid');

        $this->assertTrue($client->verifyWebhook(json_encode($data), []));
    }

    public function test_cryptomus_rejects_a_tampered_amount(): void
    {
        $key = 'payment-api-key';

        $original = ['order_id' => 'SUB-TEST', 'status' => 'paid', 'amount' => '1.00'];
        $sign = md5(base64_encode(json_encode($original, JSON_UNESCAPED_UNICODE)).$key);

        $client = new CryptomusClient($key, 'merchant-uuid');

        $this->assertFalse($client->verifyWebhook(
            json_encode(['order_id' => 'SUB-TEST', 'status' => 'paid', 'amount' => '9999.00', 'sign' => $sign]),
            [],
        ));
    }

    public function test_cryptomus_rejects_when_no_key_is_configured(): void
    {
        $client = new CryptomusClient('', 'merchant-uuid');

        $this->assertFalse($client->verifyWebhook('{"status":"paid","sign":"x"}', []));
    }

    /**
     * `wrong_amount` means they underpaid. Crediting it would sell an order
     * for whatever the payer chose to send.
     */
    public function test_cryptomus_paid_statuses(): void
    {
        $this->assertTrue(CryptomusClient::isPaidStatus('paid'));
        $this->assertTrue(CryptomusClient::isPaidStatus('paid_over'));

        $this->assertFalse(CryptomusClient::isPaidStatus('wrong_amount'));
        $this->assertFalse(CryptomusClient::isPaidStatus('confirm_check'));
        $this->assertFalse(CryptomusClient::isPaidStatus('cancel'));
        $this->assertFalse(CryptomusClient::isPaidStatus('fail'));
        $this->assertFalse(CryptomusClient::isPaidStatus('refund_paid'));
        // Never a status Cryptomus sends; it was accepted by mistake.
        $this->assertFalse(CryptomusClient::isPaidStatus('finished'));
        $this->assertFalse(CryptomusClient::isPaidStatus(null));
    }

    /** Heleket runs the same API, so it must behave identically. */
    public function test_heleket_shares_cryptomus_verification(): void
    {
        $key = 'heleket-key';

        $data = [
            'order_id' => 'SUB-TEST',
            'status' => 'paid',
            'url_callback' => 'https://hub.test/webhooks/billing/heleket',
        ];
        $data['sign'] = md5(base64_encode(json_encode(
            array_diff_key($data, ['sign' => null]),
            JSON_UNESCAPED_UNICODE,
        )).$key);

        $this->assertTrue(
            (new HeleketClient($key, 'merchant-uuid'))->verifyWebhook(json_encode($data), []),
        );
    }

    // ---- Binance Pay: uppercase HMAC-SHA512 over ts\nnonce\nbody\n -------

    public function test_binance_accepts_a_correctly_signed_webhook(): void
    {
        $secret = 'binance-secret';
        $body = '{"bizStatus":"PAY_SUCCESS"}';
        $timestamp = '1700000000000';
        $nonce = 'abc123';

        $signature = strtoupper(hash_hmac('sha512', "{$timestamp}\n{$nonce}\n{$body}\n", $secret));

        $client = new BinancePayClient('api-key', $secret);

        $this->assertTrue($client->verifyWebhook($body, [
            'signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ]));
    }

    public function test_binance_signature_must_be_uppercase(): void
    {
        $secret = 'binance-secret';
        $body = '{"bizStatus":"PAY_SUCCESS"}';
        $timestamp = '1700000000000';
        $nonce = 'abc123';

        $lowercase = hash_hmac('sha512', "{$timestamp}\n{$nonce}\n{$body}\n", $secret);

        $client = new BinancePayClient('api-key', $secret);

        $this->assertFalse($client->verifyWebhook($body, [
            'signature' => $lowercase,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ]));
    }

    public function test_binance_rejects_a_reused_nonce_signature(): void
    {
        // A signature is bound to its nonce; swapping the nonce invalidates it.
        $secret = 'binance-secret';
        $body = '{"bizStatus":"PAY_SUCCESS"}';
        $timestamp = '1700000000000';

        $signature = strtoupper(hash_hmac('sha512', "{$timestamp}\nnonce-a\n{$body}\n", $secret));

        $client = new BinancePayClient('api-key', $secret);

        $this->assertFalse($client->verifyWebhook($body, [
            'signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => 'nonce-b',
        ]));
    }

    public function test_binance_rejects_when_no_secret_is_configured(): void
    {
        $client = new BinancePayClient('api-key', '');

        $this->assertFalse($client->verifyWebhook('{"bizStatus":"PAY_SUCCESS"}', [
            'signature' => 'anything',
            'timestamp' => '1700000000000',
            'nonce' => 'abc',
        ]));
    }

    public function test_binance_paid_status(): void
    {
        $this->assertTrue(BinancePayClient::isPaidStatus('PAY_SUCCESS'));
        $this->assertFalse(BinancePayClient::isPaidStatus('PAY_CLOSED'));
        $this->assertFalse(BinancePayClient::isPaidStatus(null));
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
