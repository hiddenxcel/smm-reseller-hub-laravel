<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\MakamescoClient;
use App\Services\Payments\PaymentRequest;
use App\Services\Payments\StartTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Makamesco Pay, M-Pesa STK Push in Kenya.
 *
 * What this holds: the prompt is sent in KES to a 254 number with the secret key
 * in X-API-Key, and a wallet is credited only when Makamesco's status endpoint
 * says completed — whatever the notification itself claims.
 */
class MakamescoPayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private BotCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['currency.usd_to' => ['USD' => 1.0, 'KES' => 130.0]]);

        $this->tenant = Tenant::factory()->create();
        $this->customer = BotCustomer::factory()->for($this->tenant)->create([
            'phone' => '254712345678',
            'name' => 'Wanjiru Kamau',
            'balance' => '0.00',
        ]);

        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => 'makamesco',
            'api_key_enc' => 'sk_test_key',
            'status' => 'active',
        ]);
    }

    private function request(string $phone = '0712 345 678'): PaymentRequest
    {
        return new PaymentRequest(
            reference: 'tu_ref123',
            amount: '10.00',
            currency: 'USD',
            webhookUrl: 'https://hub.test/webhooks/payment/makamesco',
            phone: $phone,
            customerName: 'Wanjiru Kamau',
        );
    }

    private function fake(string $status): void
    {
        Http::fake([
            'makamescopay.com/api/payments/stkpush' => Http::response([
                'checkoutRequestId' => 'ws_CO_1',
                'responseCode' => '0',
            ]),
            'makamescopay.com/api/payments/status/*' => Http::response(['status' => $status]),
        ]);
    }

    public function test_it_sends_an_stk_push_in_shillings_with_the_key_in_the_header(): void
    {
        $this->fake('pending');

        $result = (new MakamescoClient('sk_test_key'))->initiate($this->request());

        $this->assertTrue($result->started);
        $this->assertSame('ws_CO_1', $result->reference);
        $this->assertNull($result->redirectUrl);

        Http::assertSent(fn ($request) => $request->url() === 'https://makamescopay.com/api/payments/stkpush'
            && $request->hasHeader('X-API-Key', 'sk_test_key')
            && $request['phoneNumber'] === '254712345678'
            && $request['amount'] === 1300
            && $request['accountReference'] === 'tu_ref123');
    }

    public function test_phone_numbers_are_made_into_254_form_or_refused(): void
    {
        foreach (['0712345678', '+254 712 345 678', '712345678', '254712345678', '0112345678'] as $input) {
            $this->assertMatchesRegularExpression('/^254[17]\d{8}$/', (string) MakamescoClient::normalisePhone($input), $input);
        }

        foreach (['', '12', '255712345678', '0812345678'] as $input) {
            $this->assertNull(MakamescoClient::normalisePhone($input), $input);
        }

        Http::fake();
        $result = (new MakamescoClient('sk'))->initiate($this->request('255 700 000 001'));
        $this->assertFalse($result->started);
        Http::assertNothingSent();
    }

    public function test_it_says_why_makamesco_refused(): void
    {
        Http::fake(['makamescopay.com/*' => Http::response(['message' => 'Invalid API key'], 401)]);

        $result = (new MakamescoClient('bad'))->initiate($this->request());

        $this->assertFalse($result->started);
        $this->assertSame('Invalid API key', $result->message);
    }

    public function test_status_words_map_safely(): void
    {
        $this->assertSame('completed', MakamescoClient::normaliseStatus('completed'));

        foreach (['failed', 'cancelled'] as $word) {
            $this->assertSame('failed', MakamescoClient::normaliseStatus($word), $word);
        }

        // Anything unrecognised must not credit a wallet.
        foreach (['pending', 'success', 'WHATEVER', ''] as $word) {
            $this->assertSame('pending', MakamescoClient::normaliseStatus($word), $word);
        }
    }

    private function startTopup()
    {
        return app(StartTopup::class)->handle(
            tenantId: (int) $this->tenant->id,
            customer: $this->customer,
            amount: '10.00',
            currency: 'USD',
            phone: '0712345678',
            gateway: 'makamesco',
        )->payment;
    }

    public function test_a_payment_makamesco_confirms_credits_the_wallet(): void
    {
        $this->fake('completed');
        $payment = $this->startTopup();

        // The notification claims nothing believable; the status call decides.
        $this->postJson(route('webhooks.payment', 'makamesco'), [
            'event' => 'payment.completed',
            'data' => ['checkoutRequestId' => 'ws_CO_1', 'status' => 'whatever'],
        ])->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame(0, bccomp('10.00', (string) $this->customer->fresh()->balance, 2));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/status/ws_CO_1'));
    }

    public function test_a_forged_notification_credits_nothing_when_makamesco_says_pending(): void
    {
        $this->fake('pending');
        $payment = $this->startTopup();

        $this->postJson(route('webhooks.payment', 'makamesco'), [
            'event' => 'payment.completed',
            'data' => ['accountReference' => $payment->transaction_ref, 'status' => 'completed'],
        ])->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, bccomp('0.00', (string) $this->customer->fresh()->balance, 2));
    }

    public function test_a_notification_for_a_reference_we_never_issued_is_ignored(): void
    {
        $this->fake('completed');

        $this->postJson(route('webhooks.payment', 'makamesco'), [
            'event' => 'payment.completed',
            'data' => ['checkoutRequestId' => 'ws_CO_other', 'accountReference' => 'INV-9'],
        ])->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/status/'));
    }
}
