<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\StartTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Money arriving from a customer: the bot starts a payment, the gateway
 * confirms it, the wallet is credited.
 *
 * The risks worth testing here are the ones that cost real money. A webhook
 * that credits without a valid signature lets anyone with the URL top up their
 * own wallet. A webhook that credits twice pays out twice. And a payment
 * recorded after the gateway is called can arrive back before it exists.
 */
class TopupFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private BotCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->customer = BotCustomer::factory()->for($this->tenant)->create([
            'phone' => '255700000001',
            'balance' => '0.00',
        ]);
    }

    private function connect(string $gateway, array $attributes = []): TenantPaymentGateway
    {
        return TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => $gateway,
            'api_key_enc' => 'api-key',
            'webhook_secret_enc' => 'shhh',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function startTopup(string $amount = '10.00'): \App\Services\Payments\TopupResult
    {
        return app(StartTopup::class)->handle(
            tenantId: (int) $this->tenant->id,
            customer: $this->customer,
            amount: $amount,
            currency: 'TZS',
            phone: '255700000001',
        );
    }

    /**
     * A gateway that accepts the request.
     *
     * Snippe reads `status` and `data.reference` off its own response, so a
     * bare 200 is not enough — it would be read as a refusal and the payment
     * would never reach 'pending'.
     */
    private function fakeGatewayAccepts(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => ['reference' => 'gw-ref-1'],
            ]),
        ]);
    }

    /**
     * Compared numerically: how many decimal places the column keeps is a
     * storage detail, and asserting on the string couples every test to it.
     */
    private function assertBalance(string $expected): void
    {
        $this->assertSame(
            0,
            bccomp($expected, (string) $this->customer->fresh()->balance, 4),
            "Expected a balance of {$expected}",
        );
    }

    /** Snippe's scheme: HMAC over "{timestamp}.{body}". */
    private function signedHeaders(string $body, string $secret = 'shhh'): array
    {
        $timestamp = (string) time();

        return [
            'signature' => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
            'timestamp' => $timestamp,
        ];
    }

    public function test_it_records_the_payment_before_calling_the_gateway(): void
    {
        $this->connect('snippe');

        // The reference must already exist when the gateway is called — a
        // webhook can arrive before the HTTP response does.
        Http::fake(function () {
            $this->assertSame(1, BotPayment::withoutTenantScope()->count());

            return Http::response(['status' => 'pending']);
        });

        $this->startTopup();

        $this->assertSame(1, BotPayment::withoutTenantScope()->count());
    }

    public function test_a_started_topup_is_pending_and_carries_our_reference(): void
    {
        $this->connect('snippe');
        $this->fakeGatewayAccepts();

        $result = $this->startTopup();

        $this->assertTrue($result->started);
        $this->assertSame('pending', $result->payment->status);
        $this->assertStringStartsWith('tu_', $result->payment->transaction_ref);
    }

    public function test_a_reseller_with_no_gateway_gets_no_gateway(): void
    {
        $result = $this->startTopup();

        $this->assertTrue($result->noGateway);
        $this->assertSame(0, BotPayment::withoutTenantScope()->count());
    }

    /**
     * A gateway with no client cannot be used, however connected it looks.
     *
     * The example is picked from config rather than hard-coded: naming one
     * outright means this silently stops testing anything the day that
     * gateway gets a client.
     */
    public function test_an_unwired_gateway_is_not_usable(): void
    {
        $unwired = collect(array_keys(Gateway::all()))
            ->first(fn (string $code) => ! Gateway::isReady($code));

        if ($unwired === null) {
            $this->markTestSkipped('Every configured gateway is wired up.');
        }

        $this->connect($unwired);

        $this->assertNull(app(GatewayFactory::class)->firstUsableFor((int) $this->tenant->id));
    }

    public function test_the_default_gateway_is_preferred(): void
    {
        $this->connect('snippe');
        $chosen = $this->connect('nowpayments');
        $chosen->makeDefault();

        $usable = app(GatewayFactory::class)->firstUsableFor((int) $this->tenant->id);

        $this->assertSame('nowpayments', $usable->gateway);
    }

    /** A paused default must not strand a paying customer. */
    public function test_a_paused_default_falls_back_to_another_active_gateway(): void
    {
        $paused = $this->connect('nowpayments');
        $paused->makeDefault();
        $paused->update(['status' => 'inactive']);

        $this->connect('snippe');

        $usable = app(GatewayFactory::class)->firstUsableFor((int) $this->tenant->id);

        $this->assertSame('snippe', $usable->gateway);
    }

    public function test_only_one_gateway_can_be_the_default(): void
    {
        $first = $this->connect('snippe');
        $second = $this->connect('nowpayments');

        $first->makeDefault();
        $second->makeDefault();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_a_failed_initiation_leaves_the_payment_marked_failed(): void
    {
        $this->connect('snippe');
        Http::fake(['*' => Http::response('nope', 500)]);

        $result = $this->startTopup();

        $this->assertFalse($result->started);
        $this->assertSame('failed', BotPayment::withoutTenantScope()->first()->status);
    }

    // ---- the webhook -----------------------------------------------------

    public function test_a_signed_success_credits_the_wallet(): void
    {
        $this->connect('snippe');
        $this->fakeGatewayAccepts();

        $payment = $this->startTopup('25.00')->payment;

        $body = json_encode(['reference' => $payment->transaction_ref, 'status' => 'success']);

        $this->call(
            'POST',
            route('webhooks.payment', 'snippe'),
            [],
            [],
            [],
            $this->serverHeaders($this->signedHeaders($body)),
            $body,
        )->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertBalance('25.00');
    }

    /**
     * The one that matters most: without this, anyone who knows the URL can
     * credit their own wallet.
     */
    public function test_an_unsigned_webhook_credits_nothing(): void
    {
        $this->connect('snippe');
        $this->fakeGatewayAccepts();

        $payment = $this->startTopup('25.00')->payment;

        $this->postJson(route('webhooks.payment', 'snippe'), [
            'reference' => $payment->transaction_ref,
            'status' => 'success',
        ])->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertBalance('0.00');
    }

    public function test_a_wrongly_signed_webhook_credits_nothing(): void
    {
        $this->connect('snippe');
        $this->fakeGatewayAccepts();

        $payment = $this->startTopup('25.00')->payment;

        $body = json_encode(['reference' => $payment->transaction_ref, 'status' => 'success']);

        $this->call(
            'POST',
            route('webhooks.payment', 'snippe'),
            [],
            [],
            [],
            $this->serverHeaders($this->signedHeaders($body, 'the-wrong-secret')),
            $body,
        )->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    /** Gateways retry for hours; the second delivery must credit nothing. */
    public function test_a_replayed_webhook_credits_only_once(): void
    {
        $this->connect('snippe');
        $this->fakeGatewayAccepts();

        $payment = $this->startTopup('25.00')->payment;

        $body = json_encode(['reference' => $payment->transaction_ref, 'status' => 'success']);
        $headers = $this->serverHeaders($this->signedHeaders($body));

        $this->call('POST', route('webhooks.payment', 'snippe'), [], [], [], $headers, $body)
            ->assertOk();
        $this->call('POST', route('webhooks.payment', 'snippe'), [], [], [], $headers, $body)
            ->assertOk();

        $this->assertBalance('25.00');
    }

    public function test_a_pending_event_credits_nothing(): void
    {
        $this->connect('snippe');
        $this->fakeGatewayAccepts();

        $payment = $this->startTopup('25.00')->payment;

        $body = json_encode(['reference' => $payment->transaction_ref, 'status' => 'pending']);

        $this->call(
            'POST',
            route('webhooks.payment', 'snippe'),
            [],
            [],
            [],
            $this->serverHeaders($this->signedHeaders($body)),
            $body,
        )->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertBalance('0.00');
    }

    /** Acknowledged so it stops being retried, but nothing is credited. */
    public function test_an_unknown_reference_is_acknowledged(): void
    {
        $this->connect('snippe');

        $this->postJson(route('webhooks.payment', 'snippe'), [
            'reference' => 'tu_never-issued',
            'status' => 'success',
        ])->assertOk();
    }

    public function test_an_unknown_gateway_is_acknowledged(): void
    {
        $this->postJson(route('webhooks.payment', 'not-a-gateway'), [
            'reference' => 'tu_x',
            'status' => 'success',
        ])->assertOk();
    }

    /** A reference issued for one gateway must not clear through another. */
    public function test_a_reference_from_another_gateway_is_refused(): void
    {
        $this->connect('snippe');
        $this->connect('nowpayments');
        $this->fakeGatewayAccepts();

        $payment = $this->startTopup('25.00')->payment;

        $this->postJson(route('webhooks.payment', 'nowpayments'), [
            'reference' => $payment->transaction_ref,
            'status' => 'success',
        ])->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_webhook_needs_no_csrf_token(): void
    {
        $this->postJson(route('webhooks.payment', 'snippe'), [])->assertOk();
    }

    /** @param array<string, string> $headers */
    private function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
