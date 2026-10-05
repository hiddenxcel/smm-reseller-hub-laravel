<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\Checkout;
use App\Services\Billing\PlatformGateways;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A reseller paying the PLATFORM with the per-country gateways: Snippe Kenya and
 * Uganda, and FimiPay's five markets. The opposite direction from
 * PaymentMarketsTest, which is a reseller's customers paying the reseller.
 */
class PlatformBillingMarketsTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAYS = [
        'snippe_ke', 'snippe_ug',
        'fimipay_ng', 'fimipay_gh', 'fimipay_cm', 'fimipay_za', 'fimipay_usd',
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        foreach (self::GATEWAYS as $code) {
            config([
                "services.billing.{$code}.api_key" => 'key-'.$code,
                "services.billing.{$code}.webhook_secret" => 'secret',
            ]);
        }

        Plan::create([
            'code' => 'order_bot',
            'service_key' => ServiceKey::OrderBot,
            'name' => 'Order Bot',
            'price_monthly' => '20.00',
            'price_yearly' => '192.00',
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function pay(string $gateway)
    {
        return app(Checkout::class)->start($this->tenant, ['order_bot'], 1, null, $gateway);
    }

    public function test_every_new_gateway_is_offered_once_it_has_keys(): void
    {
        $codes = array_column(PlatformGateways::available(), 'code');

        foreach (self::GATEWAYS as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_a_gateway_without_keys_is_not_offered(): void
    {
        config(['services.billing.fimipay_ng.api_key' => null]);

        $this->assertNotContains('fimipay_ng', array_column(PlatformGateways::available(), 'code'));
    }

    public function test_the_database_accepts_an_invoice_for_each_new_gateway(): void
    {
        foreach (self::GATEWAYS as $code) {
            $this->assertSame($code, $this->pay($code)->gateway);
        }
    }

    public function test_fimipay_asks_for_a_phone_and_snippe_hosted_pages_do_not(): void
    {
        $this->assertTrue(PlatformGateways::needsPhone('fimipay_usd'));
        $this->assertTrue(PlatformGateways::needsPhone('fimipay_gh'));
        $this->assertFalse(PlatformGateways::needsPhone('snippe_ke'));
        $this->assertFalse(PlatformGateways::needsPhone('snippe_ug'));
        $this->assertTrue(PlatformGateways::needsPhone('snippe'));
    }

    public function test_a_paid_fimipay_order_activates_the_subscription_when_fimipay_confirms(): void
    {
        Http::fake([
            'fimipay.com/api/v1/payment/order_status' => Http::response([
                'status' => 'success',
                'data' => ['payment_status' => 'SUCCESS'],
            ]),
        ]);

        $payment = $this->pay('fimipay_gh');

        $this->postJson(route('webhooks.billing', 'fimipay_gh'), ['order_id' => $payment->transaction_ref])
            ->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertTrue(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
    }

    public function test_a_forged_fimipay_success_grants_nothing_when_fimipay_says_pending(): void
    {
        Http::fake([
            'fimipay.com/api/v1/payment/order_status' => Http::response([
                'status' => 'success',
                'data' => ['payment_status' => 'PENDING'],
            ]),
        ]);

        $payment = $this->pay('fimipay_usd');

        $this->postJson(route('webhooks.billing', 'fimipay_usd'), [
            'order_id' => $payment->transaction_ref,
            'payment_status' => 'SUCCESS',
        ])->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
    }

    public function test_a_signed_snippe_kenya_completion_activates_the_subscription(): void
    {
        $payment = $this->pay('snippe_ke');

        $body = json_encode([
            'type' => 'payment.completed',
            'data' => [
                'reference' => 'SNABC123',
                'status' => 'completed',
                'metadata' => ['order_id' => $payment->transaction_ref],
            ],
        ]);
        $timestamp = (string) time();

        $this->call('POST', route('webhooks.billing', 'snippe_ke'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', "{$timestamp}.{$body}", 'secret'),
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
        ], $body)->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
    }

    public function test_an_unsigned_snippe_uganda_completion_is_refused(): void
    {
        $payment = $this->pay('snippe_ug');

        $this->postJson(route('webhooks.billing', 'snippe_ug'), [
            'type' => 'payment.completed',
            'data' => ['status' => 'completed', 'metadata' => ['order_id' => $payment->transaction_ref]],
        ])->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
    }
}
