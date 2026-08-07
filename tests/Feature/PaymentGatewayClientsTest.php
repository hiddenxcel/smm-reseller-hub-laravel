<?php

namespace Tests\Feature;

use App\Services\Payments\FlutterwaveClient;
use App\Services\Payments\PayPalClient;
use App\Services\Payments\PaymentRequest;
use App\Services\Payments\PesapalClient;
use App\Services\Payments\StripeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The four gateways added last: Stripe, Flutterwave, PayPal and Pesapal.
 *
 * Each is tested against the shape its own documentation describes, because
 * that is the only thing standing between a reseller's customers and a payment
 * that silently never arrives. The webhook halves matter most — a verifier
 * that returns true when it should not is a wallet anyone can credit.
 */
class PaymentGatewayClientsTest extends TestCase
{
    private function request(): PaymentRequest
    {
        return new PaymentRequest(
            reference: 'tu_test123',
            amount: '25.00',
            currency: 'USD',
            webhookUrl: 'https://hub.test/webhooks/payment/x',
            phone: '255700000001',
            customerName: 'Asha',
        );
    }

    // ---- Stripe ----------------------------------------------------------

    public function test_stripe_returns_the_hosted_checkout_url(): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_test_1',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
            ]),
        ]);

        $result = (new StripeClient('sk_test', 'whsec'))->initiate($this->request());

        $this->assertTrue($result->started);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $result->redirectUrl);
    }

    /**
     * Stripe charges in the smallest unit. Sending 25 instead of 2500 would
     * take twenty-five cents for a twenty-five dollar top-up.
     */
    public function test_stripe_converts_the_amount_to_minor_units(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/x'])]);

        (new StripeClient('sk_test', 'whsec'))->initiate($this->request());

        // Stripe takes form-encoded bracket keys, so the field is flat rather
        // than a nested array.
        Http::assertSent(fn ($request) => $this->sentAmount($request) === 2500);
    }

    /** Zero-decimal currencies are charged whole, not multiplied by 100. */
    public function test_stripe_leaves_zero_decimal_currencies_alone(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/x'])]);

        (new StripeClient('sk_test', 'whsec'))->initiate(new PaymentRequest(
            reference: 'tu_ugx',
            amount: '50000',
            currency: 'UGX',
            webhookUrl: 'https://hub.test/webhooks/payment/x',
        ));

        Http::assertSent(fn ($request) => $this->sentAmount($request) === 50000);
    }

    /**
     * Pull the charged amount back out of a form-encoded Stripe request.
     *
     * The bracket keys arrive as one flat map, so the nested path a JSON body
     * would have does not exist here.
     */
    private function sentAmount($request): ?int
    {
        parse_str($request->body(), $fields);

        $amount = $fields['line_items'][0]['price_data']['unit_amount'] ?? null;

        return $amount === null ? null : (int) $amount;
    }

    public function test_stripe_accepts_a_correctly_signed_webhook(): void
    {
        $body = '{"type":"checkout.session.completed"}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec');

        $client = new StripeClient('sk_test', 'whsec');

        $this->assertTrue($client->verifyWebhook($body, [
            'stripe-signature' => "t={$timestamp},v1={$signature}",
        ]));
    }

    public function test_stripe_refuses_a_wrong_signature(): void
    {
        $body = '{"type":"checkout.session.completed"}';
        $timestamp = (string) time();

        $client = new StripeClient('sk_test', 'whsec');

        $this->assertFalse($client->verifyWebhook($body, [
            'stripe-signature' => "t={$timestamp},v1=deadbeef",
        ]));
    }

    /** An old-but-validly-signed body must not be replayable. */
    public function test_stripe_refuses_a_stale_timestamp(): void
    {
        $body = '{"type":"checkout.session.completed"}';
        $timestamp = (string) (time() - 3600);
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec');

        $client = new StripeClient('sk_test', 'whsec');

        $this->assertFalse($client->verifyWebhook($body, [
            'stripe-signature' => "t={$timestamp},v1={$signature}",
        ]));
    }

    /** v0 is Stripe's test scheme and must never satisfy a live check. */
    public function test_stripe_ignores_the_v0_test_signature(): void
    {
        $body = '{"type":"checkout.session.completed"}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec');

        $client = new StripeClient('sk_test', 'whsec');

        $this->assertFalse($client->verifyWebhook($body, [
            'stripe-signature' => "t={$timestamp},v0={$signature}",
        ]));
    }

    public function test_stripe_refuses_everything_without_a_secret(): void
    {
        $body = '{"type":"checkout.session.completed"}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", '');

        $client = new StripeClient('sk_test', '');

        $this->assertFalse($client->verifyWebhook($body, [
            'stripe-signature' => "t={$timestamp},v1={$signature}",
        ]));
    }

    // ---- Flutterwave -----------------------------------------------------

    public function test_flutterwave_returns_the_payment_link(): void
    {
        Http::fake([
            'api.flutterwave.com/*' => Http::response([
                'status' => 'success',
                'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/abc'],
            ]),
        ]);

        $result = (new FlutterwaveClient('FLWSECK', 'hash'))->initiate($this->request());

        $this->assertTrue($result->started);
        $this->assertSame('https://checkout.flutterwave.com/v3/hosted/pay/abc', $result->redirectUrl);
    }

    public function test_flutterwave_sends_our_reference_as_tx_ref(): void
    {
        Http::fake([
            'api.flutterwave.com/*' => Http::response([
                'status' => 'success',
                'data' => ['link' => 'https://checkout.flutterwave.com/x'],
            ]),
        ]);

        (new FlutterwaveClient('FLWSECK', 'hash'))->initiate($this->request());

        Http::assertSent(fn ($request) => $request['tx_ref'] === 'tu_test123');
    }

    public function test_flutterwave_reports_a_refusal(): void
    {
        Http::fake([
            'api.flutterwave.com/*' => Http::response([
                'status' => 'error',
                'message' => 'Invalid currency',
            ]),
        ]);

        $result = (new FlutterwaveClient('FLWSECK', 'hash'))->initiate($this->request());

        $this->assertFalse($result->started);
        $this->assertSame('Invalid currency', $result->message);
    }

    public function test_flutterwave_accepts_the_matching_hash(): void
    {
        $client = new FlutterwaveClient('FLWSECK', 'my-secret-hash');

        $this->assertTrue($client->verifyWebhook('{}', ['verif-hash' => 'my-secret-hash']));
    }

    public function test_flutterwave_refuses_a_wrong_hash(): void
    {
        $client = new FlutterwaveClient('FLWSECK', 'my-secret-hash');

        $this->assertFalse($client->verifyWebhook('{}', ['verif-hash' => 'not-it']));
        $this->assertFalse($client->verifyWebhook('{}', []));
    }

    public function test_flutterwave_refuses_everything_without_a_hash(): void
    {
        $client = new FlutterwaveClient('FLWSECK', '');

        $this->assertFalse($client->verifyWebhook('{}', ['verif-hash' => '']));
    }

    // ---- PayPal ----------------------------------------------------------

    public function test_paypal_returns_the_approval_url(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21']),
            '*/v2/checkout/orders' => Http::response([
                'id' => '5O1',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api-m.paypal.com/v2/checkout/orders/5O1'],
                    ['rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow?token=5O1'],
                ],
            ]),
        ]);

        $result = (new PayPalClient('client', 'secret', 'WH-1'))->initiate($this->request());

        $this->assertTrue($result->started);
        $this->assertSame('https://www.paypal.com/checkoutnow?token=5O1', $result->redirectUrl);
    }

    public function test_paypal_fails_when_it_cannot_authenticate(): void
    {
        Http::fake(['*/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $result = (new PayPalClient('client', 'bad', 'WH-1'))->initiate($this->request());

        $this->assertFalse($result->started);
    }

    public function test_paypal_accepts_a_webhook_paypal_confirms(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);

        $client = new PayPalClient('client', 'secret', 'WH-1');

        $this->assertTrue($client->verifyWebhook('{"id":"WH-2"}', $this->paypalHeaders()));
    }

    public function test_paypal_refuses_a_webhook_it_does_not_confirm(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);

        $client = new PayPalClient('client', 'secret', 'WH-1');

        $this->assertFalse($client->verifyWebhook('{"id":"WH-2"}', $this->paypalHeaders()));
    }

    public function test_paypal_refuses_a_webhook_missing_its_headers(): void
    {
        Http::fake(['*' => Http::response(['verification_status' => 'SUCCESS'])]);

        $client = new PayPalClient('client', 'secret', 'WH-1');

        $this->assertFalse($client->verifyWebhook('{"id":"WH-2"}', []));
    }

    /** Without the webhook id there is nothing to verify against. */
    public function test_paypal_refuses_everything_without_a_webhook_id(): void
    {
        Http::fake(['*' => Http::response(['verification_status' => 'SUCCESS'])]);

        $client = new PayPalClient('client', 'secret', '');

        $this->assertFalse($client->verifyWebhook('{"id":"WH-2"}', $this->paypalHeaders()));
    }

    // ---- Pesapal ---------------------------------------------------------

    public function test_pesapal_returns_the_redirect_url(): void
    {
        Http::fake([
            '*/Auth/RequestToken' => Http::response(['token' => 'tok']),
            '*/SubmitOrderRequest' => Http::response([
                'order_tracking_id' => 'OT-1',
                'redirect_url' => 'https://pay.pesapal.com/iframe/OT-1',
            ]),
        ]);

        $result = (new PesapalClient('key', 'secret', 'IPN-1'))->initiate($this->request());

        $this->assertTrue($result->started);
        $this->assertSame('https://pay.pesapal.com/iframe/OT-1', $result->redirectUrl);
    }

    /** Every order must quote a registered IPN id; without one, none can be sent. */
    public function test_pesapal_refuses_to_start_without_an_ipn_id(): void
    {
        Http::fake();

        $result = (new PesapalClient('key', 'secret', ''))->initiate($this->request());

        $this->assertFalse($result->started);
        Http::assertNothingSent();
    }

    public function test_pesapal_registers_an_ipn_url(): void
    {
        Http::fake([
            '*/Auth/RequestToken' => Http::response(['token' => 'tok']),
            '*/RegisterIPN' => Http::response(['ipn_id' => 'IPN-9']),
        ]);

        $id = (new PesapalClient('key', 'secret'))->registerIpn('https://hub.test/webhooks/payment/pesapal');

        $this->assertSame('IPN-9', $id);
    }

    public function test_pesapal_reads_the_status_from_the_api(): void
    {
        Http::fake([
            '*/Auth/RequestToken' => Http::response(['token' => 'tok']),
            '*/GetTransactionStatus*' => Http::response([
                'payment_status_description' => 'Completed',
            ]),
        ]);

        $status = (new PesapalClient('key', 'secret', 'IPN-1'))->checkStatus('OT-1');

        $this->assertSame('completed', $status);
    }

    /**
     * Pesapal's IPN is unauthenticated by design, so the notification alone can
     * never be treated as proof — the status call is what decides.
     */
    public function test_pesapal_never_verifies_a_webhook_on_its_own(): void
    {
        $client = new PesapalClient('key', 'secret', 'IPN-1');

        $this->assertFalse($client->verifyWebhook('{"OrderTrackingId":"OT-1"}', []));
    }

    /** @return array<string, string> */
    private function paypalHeaders(): array
    {
        return [
            'paypal-transmission-id' => 'tid',
            'paypal-transmission-time' => '2026-08-03T00:00:00Z',
            'paypal-cert-url' => 'https://api.paypal.com/cert.pem',
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-transmission-sig' => 'sig',
        ];
    }
}
