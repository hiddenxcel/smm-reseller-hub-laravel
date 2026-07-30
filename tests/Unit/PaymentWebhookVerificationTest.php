<?php

namespace Tests\Unit;

use App\Services\Payments\BinancePayClient;
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

    public function test_nowpayments_paid_statuses(): void
    {
        $this->assertTrue(NowPaymentsClient::isPaidStatus('finished'));
        $this->assertTrue(NowPaymentsClient::isPaidStatus('confirmed'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus('waiting'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus('partially_paid'));
        $this->assertFalse(NowPaymentsClient::isPaidStatus(null));
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
