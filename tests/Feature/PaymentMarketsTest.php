<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\ExchangeRates;
use App\Services\Payments\FimipayClient;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\PaymentRequest;
use App\Services\Payments\SnippeClient;
use App\Services\Payments\StartTopup;
use App\Services\Payments\TopupResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Snippe in Tanzania, Kenya and Uganda, and FimiPay in five markets.
 *
 * What this holds: each market is its own gateway, a shop priced in dollars is
 * charged the right local amount, the webhook is matched back to its payment
 * however the gateway names it, and nothing credits a wallet unless it is
 * signed and says the payment completed.
 */
class PaymentMarketsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private BotCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['currency.usd_to' => [
            'USD' => 1.0, 'TZS' => 2600.0, 'KES' => 130.0, 'UGX' => 3700.0,
            'NGN' => 1500.0, 'GHS' => 15.0, 'XAF' => 600.0, 'ZAR' => 18.0,
        ]]);

        $this->tenant = Tenant::factory()->create();
        $this->customer = BotCustomer::factory()->for($this->tenant)->create([
            'phone' => '255700000001',
            'name' => 'Asha Mwangi',
            'balance' => '0.00',
        ]);
    }

    private function connect(string $gateway, string $secret = 'shhh'): TenantPaymentGateway
    {
        return TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => $gateway,
            'api_key_enc' => 'api-key',
            'webhook_secret_enc' => $secret,
            'status' => 'active',
        ]);
    }

    private function request(string $amount = '10.00', string $currency = 'USD', string $phone = '255700000001'): PaymentRequest
    {
        return new PaymentRequest(
            reference: 'tu_ref123',
            amount: $amount,
            currency: $currency,
            webhookUrl: 'https://hub.test/webhooks/payment/x',
            phone: $phone,
            customerName: 'Asha Mwangi',
        );
    }

    private function startTopup(string $gateway, string $amount = '10.00', string $currency = 'USD', string $phone = ''): TopupResult
    {
        $this->connect($gateway);

        return app(StartTopup::class)->handle(
            tenantId: (int) $this->tenant->id,
            customer: $this->customer,
            amount: $amount,
            currency: $currency,
            phone: $phone,
            gateway: $gateway,
        );
    }

    private function snippeSigned(string $body, string $secret = 'shhh'): array
    {
        $timestamp = (string) time();

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
        ];
    }

    private function fimipaySigned(string $body, string $secret = 'shhh'): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FIMIPAY_SIGNATURE' => hash_hmac('sha256', $body, $secret),
        ];
    }

    private function webhook(string $gateway, string $body, array $server)
    {
        return $this->call('POST', route('webhooks.payment', $gateway), [], [], [], $server, $body);
    }

    // ---- exchange rates ----------------------------------------------------

    public function test_a_dollar_amount_converts_through_the_rate_table(): void
    {
        $this->assertSame('26000.0000', ExchangeRates::convert('10', 'USD', 'TZS'));
        $this->assertSame('13000.0000', ExchangeRates::convert('100', 'USD', 'KES'));
        // Between two non-dollar currencies, via the dollar.
        $this->assertSame('5.0000', ExchangeRates::convert('13000', 'TZS', 'USD'));
        $this->assertSame('10.0000', ExchangeRates::convert('10', 'TZS', 'tzs'));
    }

    public function test_a_currency_with_no_rate_is_refused_not_guessed(): void
    {
        $this->assertNull(ExchangeRates::convert('10', 'USD', 'XYZ'));
        $this->assertNull(ExchangeRates::convert('10', 'XYZ', 'USD'));
    }

    // ---- Snippe: Tanzania --------------------------------------------------

    public function test_tanzania_charges_a_dollar_shop_in_shillings(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['status' => 'success', 'data' => ['reference' => 'SN123']])]);

        $result = (new SnippeClient('key', 'secret', 'TZS'))->initiate($this->request('10.00', 'USD'));

        $this->assertTrue($result->started);
        $this->assertTrue($result->redirectUrl === null, 'a USSD push has no page');

        Http::assertSent(function ($request) {
            $this->assertSame('https://api.snippe.sh/v1/payments', $request->url());
            $this->assertSame(26000, $request['details']['amount']);
            $this->assertSame('TZS', $request['details']['currency']);
            $this->assertSame('tu_ref123', $request['metadata']['order_id']);

            return true;
        });
    }

    public function test_tanzania_will_not_push_without_a_number(): void
    {
        Http::fake();

        $result = (new SnippeClient('key', 'secret', 'TZS'))->initiate($this->request('10.00', 'USD', ''));

        $this->assertFalse($result->started);
        Http::assertNothingSent();
    }

    public function test_an_unknown_shop_currency_stops_before_calling_snippe(): void
    {
        Http::fake();

        $result = (new SnippeClient('key', 'secret'))->initiate($this->request('10.00', 'XYZ'));

        $this->assertFalse($result->started);
        $this->assertStringContainsString('exchange rate', $result->message);
        Http::assertNothingSent();
    }

    // ---- Snippe: Kenya and Uganda ------------------------------------------

    /** @dataProvider sessionMarkets */
    public function test_kenya_and_uganda_open_a_hosted_checkout_priced_in_shillings(string $market): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response([
            'status' => 'success',
            'data' => ['reference' => 'PAY999', 'checkout_url' => 'https://checkout.snippe.sh/s/PAY999'],
        ])]);

        $result = (new SnippeClient('key', 'secret', $market))->initiate($this->request('10.00', 'USD'));

        $this->assertTrue($result->started);
        $this->assertSame('https://checkout.snippe.sh/s/PAY999', $result->redirectUrl);
        $this->assertSame('PAY999', $result->reference);

        Http::assertSent(function ($request) {
            // The /api prefix matters: without it the sessions path 404s.
            $this->assertSame('https://api.snippe.sh/api/v1/sessions', $request->url());
            // Snippe refuses any currency but TZS, even for a Kenyan payer.
            $this->assertSame('TZS', $request['currency']);
            $this->assertSame(26000, $request['amount']);
            $this->assertSame(['mobile_money'], $request['allowed_methods']);
            $this->assertSame('tu_ref123', $request['metadata']['order_id']);
            $this->assertStringEndsWith('/payment/thanks', $request['redirect_url']);

            return true;
        });
    }

    public static function sessionMarkets(): array
    {
        return ['kenya' => ['KES'], 'uganda' => ['UGX']];
    }

    public function test_a_refused_session_says_why(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['message' => 'currency must be TZS'], 422)]);

        $result = (new SnippeClient('key', 'secret', 'KES'))->initiate($this->request());

        $this->assertFalse($result->started);
        $this->assertSame('currency must be TZS', $result->message);
    }

    public function test_the_status_lookup_picks_the_endpoint_from_the_reference(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['data' => ['status' => 'completed']])]);

        $client = new SnippeClient('key', 'secret', 'KES');

        $this->assertSame('completed', $client->checkStatus('PAY999'));
        $this->assertSame('completed', $client->checkStatus('SN123'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v1/sessions/PAY999'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/payments/SN123') && ! str_contains($r->url(), '/api/'));
    }

    public function test_snippe_accepts_its_real_header_names_and_the_old_ones(): void
    {
        $client = new SnippeClient('key', 'secret');
        $body = '{"type":"payment.completed"}';
        $time = (string) time();
        $signature = hash_hmac('sha256', "{$time}.{$body}", 'secret');

        $this->assertTrue($client->verifyWebhook($body, ['x-webhook-signature' => $signature, 'x-webhook-timestamp' => $time]));
        $this->assertTrue($client->verifyWebhook($body, ['X-Webhook-Signature' => $signature, 'X-Webhook-Timestamp' => $time]));
        $this->assertTrue($client->verifyWebhook($body, ['signature' => $signature, 'timestamp' => $time]));
        $this->assertFalse($client->verifyWebhook($body, ['x-webhook-signature' => 'nope', 'x-webhook-timestamp' => $time]));
    }

    public function test_only_a_completed_snippe_payment_counts(): void
    {
        $this->assertTrue(SnippeClient::isCompleted(['type' => 'payment.completed', 'data' => ['status' => 'completed']]));
        $this->assertTrue(SnippeClient::isCompleted(['data' => ['status' => 'completed']]));

        foreach (['failed', 'voided', 'expired', 'cancelled', 'pending'] as $status) {
            $this->assertFalse(SnippeClient::isCompleted(['data' => ['status' => $status]]), $status);
        }

        $this->assertFalse(SnippeClient::isCompleted(['type' => 'payment.failed', 'data' => ['status' => 'completed']]));
        $this->assertFalse(SnippeClient::isCompleted(['type' => 'payment.created']));
    }

    // ---- matching a webhook back to its payment ----------------------------

    public function test_a_paid_webhook_is_matched_by_the_metadata_we_sent(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response([
            'status' => 'success',
            'data' => ['reference' => 'PAY555', 'checkout_url' => 'https://checkout.snippe.sh/s/x'],
        ])]);

        $payment = $this->startTopup('snippe_ke', '10.00')->payment;

        // Snippe's own id in data.reference; ours only in the metadata.
        $body = json_encode([
            'type' => 'payment.completed',
            'data' => ['reference' => 'SN-NOT-OURS', 'status' => 'completed', 'metadata' => ['order_id' => $payment->transaction_ref]],
        ]);

        $this->webhook('snippe_ke', $body, $this->snippeSigned($body))->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame(0, bccomp('10.00', (string) $this->customer->fresh()->balance, 2));
    }

    public function test_a_webhook_carrying_only_the_gateways_id_is_still_found(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response([
            'status' => 'success',
            'data' => ['reference' => 'SN777', 'status' => 'pending'],
        ])]);

        $payment = $this->startTopup('snippe', '10.00', 'USD', '255700000001')->payment;

        // The started payment remembers the id the gateway gave it...
        $this->assertSame('SN777', $payment->fresh()->gateway_reference);

        // ...so a webhook that mentions nothing else still finds it.
        $body = json_encode(['type' => 'payment.completed', 'data' => ['reference' => 'SN777', 'status' => 'completed']]);

        $this->webhook('snippe', $body, $this->snippeSigned($body))->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
    }

    public function test_a_failed_snippe_payment_credits_nothing(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['status' => 'success', 'data' => ['reference' => 'PAY1', 'checkout_url' => 'https://x']])]);

        $payment = $this->startTopup('snippe_ug')->payment;

        $body = json_encode(['type' => 'payment.failed', 'data' => ['status' => 'failed', 'metadata' => ['order_id' => $payment->transaction_ref]]]);

        $this->webhook('snippe_ug', $body, $this->snippeSigned($body))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, bccomp('0', (string) $this->customer->fresh()->balance, 2));
    }

    public function test_an_unsigned_snippe_webhook_is_refused(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['status' => 'success', 'data' => ['reference' => 'PAY1', 'checkout_url' => 'https://x']])]);

        $payment = $this->startTopup('snippe_ke')->payment;
        $body = json_encode(['type' => 'payment.completed', 'data' => ['status' => 'completed', 'metadata' => ['order_id' => $payment->transaction_ref]]]);

        $this->webhook('snippe_ke', $body, ['CONTENT_TYPE' => 'application/json'])->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    // ---- FimiPay -----------------------------------------------------------

    /** @dataProvider fimipayMarkets */
    public function test_each_fimipay_market_charges_its_own_currency_and_method(
        string $code,
        string $currency,
        string $method,
        string $phone,
        string $expectedPhone,
        string|int|float $expectedAmount,
    ): void {
        Http::fake(['fimipay.com/*' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'tu_ref123', 'payment_gateway_url' => 'https://pay.fimipay.com/o/abc'],
        ])]);

        $result = (new FimipayClient('sk_test_x', 'whsec', $code))->initiate($this->request('10.00', 'USD', $phone));

        $this->assertTrue($result->started, (string) $result->message);
        $this->assertSame('https://pay.fimipay.com/o/abc', $result->redirectUrl);

        Http::assertSent(function ($request) use ($currency, $method, $expectedPhone, $expectedAmount) {
            $this->assertSame('https://fimipay.com/api/v1/payment/create_order', $request->url());
            $this->assertSame($currency, $request['currency']);
            $this->assertSame($method, $request['payment_method']);
            $this->assertSame($expectedPhone, $request['buyer_phone']);
            $this->assertEquals($expectedAmount, $request['amount']);
            // Our reference, so the webhook's order_id is one we can look up.
            $this->assertSame('tu_ref123', $request['order_id']);
            $this->assertStringEndsWith('/payment/thanks', $request['redirect_url']);
            $this->assertStringContainsString('@', $request['buyer_email']);

            return true;
        });
    }

    public static function fimipayMarkets(): array
    {
        return [
            // A local leading 0 becomes the country code.
            'nigeria' => ['fimipay_ng', 'NGN', 'bank', '0803 123 4567', '2348031234567', 15000],
            'ghana' => ['fimipay_gh', 'GHS', 'mobile', '024 123 4567', '233241234567', 150],
            'cameroon' => ['fimipay_cm', 'XAF', 'mobile', '6 71 23 45 67', '237671234567', 6000],
            'south africa' => ['fimipay_za', 'ZAR', 'card', '082 123 4567', '27821234567', 180],
            // Dollars keep their cents and take any international number.
            'international' => ['fimipay_usd', 'USD', 'card', '+44 7700 900123', '447700900123', 10.00],
        ];
    }

    public function test_a_number_that_is_not_a_number_is_refused_before_calling_fimipay(): void
    {
        Http::fake();

        $result = (new FimipayClient('sk', 'wh', 'fimipay_ng'))->initiate($this->request('10.00', 'USD', '12'));

        $this->assertFalse($result->started);
        Http::assertNothingSent();
    }

    public function test_fimipay_with_no_rate_for_the_shop_currency_says_so(): void
    {
        Http::fake();

        $result = (new FimipayClient('sk', 'wh', 'fimipay_gh'))->initiate($this->request('10.00', 'XYZ', '024 123 4567'));

        $this->assertFalse($result->started);
        $this->assertStringContainsString('exchange rate', $result->message);
        Http::assertNothingSent();
    }

    public function test_an_unknown_fimipay_market_is_refused(): void
    {
        Http::fake();

        $this->assertFalse((new FimipayClient('sk', 'wh', 'fimipay_xx'))->initiate($this->request())->started);
        Http::assertNothingSent();
    }

    public function test_fimipay_says_why_it_refused(): void
    {
        Http::fake(['fimipay.com/*' => Http::response(['status' => 'error', 'message' => 'Invalid API key'], 401)]);

        $result = (new FimipayClient('bad', 'wh', 'fimipay_usd'))->initiate($this->request());

        $this->assertFalse($result->started);
        $this->assertSame('Invalid API key', $result->message);
    }

    public function test_fimipay_status_words_map_safely(): void
    {
        foreach (['SUCCESS', 'completed', 'PAID', 'SUCCESSFUL'] as $word) {
            $this->assertSame('completed', FimipayClient::normaliseStatus($word), $word);
        }

        foreach (['FAILED', 'cancelled', 'EXPIRED', 'DECLINED'] as $word) {
            $this->assertSame('failed', FimipayClient::normaliseStatus($word), $word);
        }

        // Anything unrecognised must not credit a wallet.
        foreach (['PENDING', 'PROCESSING', 'WHATEVER', ''] as $word) {
            $this->assertSame('pending', FimipayClient::normaliseStatus($word), $word);
        }
    }

    public function test_the_fimipay_signature_is_over_the_raw_body(): void
    {
        $client = new FimipayClient('sk', 'whsec', 'fimipay_usd');
        $body = '{"order_id":"tu_1","payment_status":"SUCCESS"}';

        $this->assertTrue($client->verifyWebhook($body, ['x-fimipay-signature' => hash_hmac('sha256', $body, 'whsec')]));
        $this->assertTrue($client->verifyWebhook($body, ['X-Fimipay-Signature' => hash_hmac('sha256', $body, 'whsec')]));
        $this->assertFalse($client->verifyWebhook($body.' ', ['x-fimipay-signature' => hash_hmac('sha256', $body, 'whsec')]));
        $this->assertFalse($client->verifyWebhook($body, []));
        $this->assertFalse((new FimipayClient('sk', '', 'fimipay_usd'))->verifyWebhook($body, ['x-fimipay-signature' => hash_hmac('sha256', $body, '')]));
    }

    public function test_a_signed_fimipay_success_credits_the_wallet(): void
    {
        Http::fake(['fimipay.com/*' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'x', 'payment_gateway_url' => 'https://pay.fimipay.com/o/abc'],
        ])]);

        $payment = $this->startTopup('fimipay_gh', '10.00', 'USD', '024 123 4567')->payment;

        $body = json_encode(['order_id' => $payment->transaction_ref, 'payment_status' => 'SUCCESS', 'amount' => 150]);

        $this->webhook('fimipay_gh', $body, $this->fimipaySigned($body))->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame(0, bccomp('10.00', (string) $this->customer->fresh()->balance, 2));
    }

    public function test_a_fimipay_event_name_alone_is_enough(): void
    {
        Http::fake(['fimipay.com/*' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'x', 'payment_gateway_url' => 'https://pay.fimipay.com/o/abc'],
        ])]);

        $payment = $this->startTopup('fimipay_usd', '10.00', 'USD')->payment;

        $body = json_encode(['event' => 'payment.success', 'order_id' => $payment->transaction_ref]);

        $this->webhook('fimipay_usd', $body, $this->fimipaySigned($body))->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
    }

    public function test_fimipay_pending_failed_and_unsigned_credit_nothing(): void
    {
        Http::fake(['fimipay.com/*' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'x', 'payment_gateway_url' => 'https://pay.fimipay.com/o/abc'],
        ])]);

        $payment = $this->startTopup('fimipay_ng', '10.00', 'USD', '0803 123 4567')->payment;

        foreach (['PENDING', 'FAILED', 'EXPIRED'] as $status) {
            $body = json_encode(['order_id' => $payment->transaction_ref, 'payment_status' => $status]);
            $this->webhook('fimipay_ng', $body, $this->fimipaySigned($body))->assertOk();
        }

        $paid = json_encode(['order_id' => $payment->transaction_ref, 'payment_status' => 'SUCCESS']);
        $this->webhook('fimipay_ng', $paid, ['CONTENT_TYPE' => 'application/json'])->assertStatus(401);
        $this->webhook('fimipay_ng', $paid, $this->fimipaySigned($paid, 'wrong-secret'))->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, bccomp('0', (string) $this->customer->fresh()->balance, 2));
    }

    public function test_a_replayed_fimipay_webhook_credits_once(): void
    {
        Http::fake(['fimipay.com/*' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'x', 'payment_gateway_url' => 'https://pay.fimipay.com/o/abc'],
        ])]);

        $payment = $this->startTopup('fimipay_za', '10.00', 'USD', '082 123 4567')->payment;
        $body = json_encode(['order_id' => $payment->transaction_ref, 'payment_status' => 'SUCCESS']);

        $this->webhook('fimipay_za', $body, $this->fimipaySigned($body))->assertOk();
        $this->webhook('fimipay_za', $body, $this->fimipaySigned($body))->assertOk();

        $this->assertSame(0, bccomp('10.00', (string) $this->customer->fresh()->balance, 2));
    }

    // ---- wiring ------------------------------------------------------------

    public function test_every_market_is_a_ready_gateway_the_factory_can_build(): void
    {
        $codes = ['snippe', 'snippe_ke', 'snippe_ug', 'fimipay_ng', 'fimipay_gh', 'fimipay_cm', 'fimipay_za', 'fimipay_usd'];

        foreach ($codes as $code) {
            $this->assertTrue(Gateway::exists($code), "{$code} is not configured");
            $this->assertTrue(Gateway::isReady($code), "{$code} is not ready");

            $client = app(GatewayFactory::class)->make($this->connect($code));

            $this->assertNotNull($client, "{$code} builds no client");
            $this->assertInstanceOf(str_starts_with($code, 'snippe') ? SnippeClient::class : FimipayClient::class, $client);
        }
    }

    public function test_only_the_push_and_phone_markets_ask_for_a_number(): void
    {
        foreach (['snippe', 'fimipay_ng', 'fimipay_gh', 'fimipay_cm'] as $code) {
            $this->assertTrue(Gateway::needsPhone($code), "{$code} should ask for a number");
        }

        foreach (['snippe_ke', 'snippe_ug', 'fimipay_za', 'fimipay_usd'] as $code) {
            $this->assertFalse(Gateway::needsPhone($code), "{$code} should not");
        }
    }

    public function test_a_gateway_the_bot_did_not_ask_a_number_for_uses_the_customers_own(): void
    {
        Http::fake(['fimipay.com/*' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'x', 'payment_gateway_url' => 'https://pay.fimipay.com/o/abc'],
        ])]);

        // No phone passed — as for a card gateway the bot asks nothing for.
        $result = $this->startTopup('fimipay_usd', '10.00', 'USD', '');

        $this->assertTrue($result->started, (string) $result->message);

        Http::assertSent(fn ($request) => $request['buyer_phone'] === '255700000001');
    }

    public function test_a_started_payment_remembers_the_gateways_id(): void
    {
        Http::fake(['fimipay.com/*' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'fp-order-9', 'payment_gateway_url' => 'https://pay.fimipay.com/o/abc'],
        ])]);

        $payment = $this->startTopup('fimipay_usd')->payment;

        $this->assertSame('fp-order-9', $payment->fresh()->gateway_reference);
        $this->assertSame($payment->id, BotPayment::findByRefAnyTenant('fp-order-9')->id);
        $this->assertSame($payment->id, BotPayment::findByRefAnyTenant($payment->transaction_ref)->id);
    }

    public function test_the_page_a_gateway_returns_the_customer_to_exists_and_claims_nothing(): void
    {
        $this->get(route('payment.thanks'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Public/PaymentThanks'));
    }
}
