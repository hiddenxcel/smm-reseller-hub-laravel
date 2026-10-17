<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\Checkout;
use App\Services\Billing\ReconcileSubscriptionPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A reseller who paid the platform by FimiPay card must get their subscription
 * even when FimiPay's notification never arrives — which is how a $20 payment
 * once sat unactivated. The platform asks FimiPay itself, on a schedule and when
 * the customer is sent back, and still believes only FimiPay's own answer.
 */
class ReconcileSubscriptionPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->tenant = Tenant::factory()->create();

        config([
            'services.billing.fimipay_usd.api_key' => 'sk_test',
            'services.billing.fimipay_usd.webhook_secret' => 'secret',
        ]);

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

    private function pay()
    {
        return app(Checkout::class)->start($this->tenant, ['order_bot'], 1, null, 'fimipay_usd');
    }

    private function fimipaySays(string $status): void
    {
        Http::fake([
            'fimipay.com/api/v1/payment/order_status' => Http::response([
                'status' => 'success',
                'data' => ['payment_status' => $status],
            ]),
        ]);
    }

    public function test_the_scheduled_run_activates_an_order_fimipay_says_is_paid(): void
    {
        $this->fimipaySays('SUCCESS');
        $payment = $this->pay();

        $result = app(ReconcileSubscriptionPayments::class)->run();

        $this->assertSame(['checked' => 1, 'activated' => 1], $result);
        $this->assertSame('success', $payment->fresh()->status);
        $this->assertTrue(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
    }

    public function test_an_unpaid_order_stays_pending_and_is_not_marked_failed(): void
    {
        $this->fimipaySays('FAILED');
        $payment = $this->pay();

        $result = app(ReconcileSubscriptionPayments::class)->run();

        $this->assertSame(['checked' => 1, 'activated' => 0], $result);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
    }

    public function test_running_twice_grants_nothing_twice(): void
    {
        $this->fimipaySays('SUCCESS');
        $payment = $this->pay();
        $reconcile = app(ReconcileSubscriptionPayments::class);

        $this->assertTrue($reconcile->check($payment));
        $this->assertFalse($reconcile->check($payment->fresh()));
    }

    public function test_an_order_is_not_asked_about_again_within_a_minute(): void
    {
        $this->fimipaySays('PENDING');
        $this->pay();

        $reconcile = app(ReconcileSubscriptionPayments::class);

        $this->assertSame(1, $reconcile->run()['checked']);
        $this->assertSame(0, $reconcile->run()['checked']);
    }

    public function test_an_order_older_than_two_days_is_left_alone(): void
    {
        $this->fimipaySays('SUCCESS');
        $payment = $this->pay();
        $payment->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertSame(['checked' => 0, 'activated' => 0], app(ReconcileSubscriptionPayments::class)->run());
    }

    public function test_coming_back_from_paying_activates_without_waiting_for_the_schedule(): void
    {
        $this->fimipaySays('SUCCESS');
        $payment = $this->pay();

        $this->get(route('payment.thanks', ['tx_ref' => $payment->transaction_ref, 'status' => 'successful']))
            ->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertTrue(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
    }

    public function test_a_return_url_claiming_success_grants_nothing_when_fimipay_disagrees(): void
    {
        $this->fimipaySays('PENDING');
        $payment = $this->pay();

        $this->get(route('payment.thanks', ['tx_ref' => $payment->transaction_ref, 'status' => 'successful']))
            ->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_thanks_page_still_loads_for_a_reference_nobody_issued(): void
    {
        Http::fake();

        $this->get(route('payment.thanks', ['tx_ref' => 'SUB-NOPE']))->assertOk();

        Http::assertNothingSent();
    }
}
