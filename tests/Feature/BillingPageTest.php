<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The billing screen, and the webhook that makes a payment mean something.
 *
 * The engine underneath is covered by BillingTest. What is tested here is the
 * wiring: that the page reports what a reseller actually holds, that a
 * checkout reaches a gateway without granting anything yet, and that a
 * confirmed payment activates — once, however many times it is delivered.
 */
class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();

        // A gateway with no keys is not offered at all, so the tests need one
        // that looks configured.
        config([
            'services.billing.cryptomus.api_key' => 'test-key',
            'services.billing.cryptomus.merchant_id' => 'test-merchant',
        ]);

        foreach ([ServiceKey::OrderBot, ServiceKey::SupportBot] as $service) {
            Plan::create([
                'code' => $service->value,
                'service_key' => $service,
                'name' => str($service->value)->headline()->toString(),
                'price_monthly' => '20.00',
                'price_yearly' => '192.00',
                'currency' => 'USD',
                'status' => 'active',
            ]);
        }
    }

    // ---- the page --------------------------------------------------------

    public function test_it_lists_what_is_on_sale_with_a_price_for_every_term(): void
    {
        $this->actingAs($this->tenant)
            ->get(route('billing'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Billing/Index')
                ->has('services', 2)
                // 20.00 x 12 less the 25% twelve-month discount.
                ->where('services.0.termPrices.12', 18000)
                ->where('services.0.termPrices.1', 2000));
    }

    public function test_a_service_never_subscribed_to_is_reported_as_locked(): void
    {
        $this->actingAs($this->tenant)
            ->get(route('billing'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('services.0.state', 'locked')
                ->where('services.0.daysLeft', null));
    }

    public function test_an_active_subscription_reports_the_days_it_has_left(): void
    {
        Subscription::factory()->for($this->tenant)->create([
            'service_key' => ServiceKey::OrderBot->value,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->tenant)
            ->get(route('billing'))
            ->assertInertia(function (AssertableInertia $page) {
                $service = collect($page->toArray()['props']['services'])
                    ->firstWhere('key', 'order_bot');

                $this->assertSame('active', $service['state']);
                $this->assertSame(10, $service['daysLeft']);
            });
    }

    /**
     * "Expired 3 days ago" and "expires today" are different problems, so the
     * count goes negative rather than clamping at zero.
     */
    public function test_a_lapsed_subscription_reports_a_negative_day_count(): void
    {
        Subscription::factory()->for($this->tenant)->create([
            'service_key' => ServiceKey::OrderBot->value,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->subDays(3),
        ]);

        $this->actingAs($this->tenant)
            ->get(route('billing'))
            ->assertInertia(function (AssertableInertia $page) {
                $service = collect($page->toArray()['props']['services'])
                    ->firstWhere('key', 'order_bot');

                $this->assertSame('locked', $service['state']);
                $this->assertSame(-3, $service['daysLeft']);
            });
    }

    /** A gateway with no keys is left out rather than offered and then failing. */
    public function test_only_gateways_holding_real_credentials_are_offered(): void
    {
        // Blanked here rather than assumed absent: a developer whose .env holds
        // real Snippe keys would otherwise see this fail for the wrong reason.
        config(['services.billing.snippe.api_key' => null]);

        $this->actingAs($this->tenant)
            ->get(route('billing'))
            ->assertInertia(function (AssertableInertia $page) {
                $codes = collect($page->toArray()['props']['gateways'])->pluck('code');

                $this->assertContains('cryptomus', $codes);
                $this->assertNotContains('snippe', $codes);
            });
    }

    public function test_the_page_needs_a_login(): void
    {
        $this->get(route('billing'))->assertRedirect(route('login'));
    }

    public function test_it_does_not_show_another_tenants_invoices(): void
    {
        $other = Tenant::factory()->create();

        SubscriptionPayment::withoutTenantScope()->create([
            'tenant_id' => $other->id,
            'gateway' => 'cryptomus',
            'transaction_ref' => 'SUB-NOTYOURS',
            'amount' => '20.00',
            'credit_applied' => '0.00',
            'currency' => 'USD',
            'months' => 1,
            'items' => [['type' => 'service', 'key' => 'order_bot', 'months' => 1]],
            'status' => 'success',
        ]);

        $this->actingAs($this->tenant)
            ->get(route('billing'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('invoices', 0));
    }

    // ---- checkout --------------------------------------------------------

    public function test_a_checkout_records_a_pending_invoice_and_grants_nothing(): void
    {
        Http::fake(['*' => Http::response(['state' => 0, 'result' => ['url' => 'https://pay.example/abc']])]);

        $this->actingAs($this->tenant)->post(route('billing.checkout'), [
            'services' => ['order_bot'],
            'months' => 1,
            'gateway' => 'cryptomus',
        ])->assertRedirect('https://pay.example/abc');

        $this->assertDatabaseHas('subscription_payments', [
            'tenant_id' => $this->tenant->id,
            'status' => 'pending',
            'amount' => '20.00',
        ]);

        // Nothing bought yet: the gateway has not confirmed anything.
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_an_empty_cart_is_refused(): void
    {
        $this->actingAs($this->tenant)->post(route('billing.checkout'), [
            'services' => [],
            'months' => 1,
            'gateway' => 'cryptomus',
        ])->assertSessionHasErrors('services');
    }

    public function test_a_term_we_do_not_sell_is_refused(): void
    {
        $this->actingAs($this->tenant)->post(route('billing.checkout'), [
            'services' => ['order_bot'],
            'months' => 7,
            'gateway' => 'cryptomus',
        ])->assertSessionHasErrors('months');
    }

    /** Mobile money pushes a prompt to a handset; without a number there is nowhere to push it. */
    public function test_mobile_money_without_a_phone_number_is_refused(): void
    {
        config([
            'services.billing.snippe.api_key' => 'test-key',
        ]);

        $this->actingAs($this->tenant)->post(route('billing.checkout'), [
            'services' => ['order_bot'],
            'months' => 1,
            'gateway' => 'snippe',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('subscription_payments', 0);
    }

    /**
     * A gateway that fails leaves a pending invoice holding referral credit.
     * Abandoning it puts the credit back — otherwise a broken checkout quietly
     * burns it.
     */
    public function test_a_gateway_failure_abandons_the_invoice_and_refunds_the_credit(): void
    {
        $this->tenant->update(['referral_credit' => '5.00']);

        Http::fake(['*' => Http::response(['state' => 1, 'message' => 'nope'], 400)]);

        $this->actingAs($this->tenant)->post(route('billing.checkout'), [
            'services' => ['order_bot'],
            'months' => 1,
            'gateway' => 'cryptomus',
        ])->assertSessionHas('error');

        $this->assertDatabaseHas('subscription_payments', ['status' => 'failed']);
        $this->assertSame('5.00', $this->tenant->fresh()->referral_credit);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    // ---- the webhook -----------------------------------------------------

    private function pendingPayment(string $ref = 'SUB-TESTREF0001'): SubscriptionPayment
    {
        return SubscriptionPayment::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => 'cryptomus',
            'transaction_ref' => $ref,
            'amount' => '20.00',
            'credit_applied' => '0.00',
            'currency' => 'USD',
            'months' => 1,
            'items' => [['type' => 'service', 'key' => 'order_bot', 'months' => 1]],
            'status' => 'pending',
        ]);
    }

    /**
     * A payload signed the way Cryptomus does it: md5 of the base64-encoded
     * body plus the API key, with the signature carried inside the body
     * itself rather than in a header.
     */
    private function signed(array $payload): array
    {
        $sign = md5(base64_encode(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ).'test-key');

        return [...$payload, 'sign' => $sign];
    }

    public function test_a_confirmed_payment_activates_the_subscription(): void
    {
        $payment = $this->pendingPayment();

        $signed = $this->signed([
            'order_id' => $payment->transaction_ref,
            'status' => 'paid',
        ]);

        $this->postJson(
            route('webhooks.billing', 'cryptomus'),
            $signed,
        )->assertOk();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertTrue(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
    }

    /** Gateways retry. A second delivery must not grant a second month. */
    public function test_a_replayed_webhook_grants_nothing_twice(): void
    {
        $payment = $this->pendingPayment();

        $signed = $this->signed([
            'order_id' => $payment->transaction_ref,
            'status' => 'paid',
        ]);

        foreach (range(1, 3) as $ignored) {
            $this->postJson(
                route('webhooks.billing', 'cryptomus'),
                $signed,
            )->assertOk();
        }

        $subscription = Subscription::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        // One month from one payment, not three.
        $this->assertLessThanOrEqual(
            now()->addMonth()->addDay(),
            $subscription->ends_at,
        );
        $this->assertDatabaseCount('subscriptions', 1);
    }

    /**
     * The old platform trusted a webhook when no secret was configured, which
     * meant anyone who guessed the URL could activate a subscription free.
     */
    public function test_an_unsigned_webhook_is_refused(): void
    {
        $payment = $this->pendingPayment();

        $this->postJson(route('webhooks.billing', 'cryptomus'), [
            'order_id' => $payment->transaction_ref,
            'status' => 'paid',
        ])->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_pending_status_grants_nothing(): void
    {
        $payment = $this->pendingPayment();

        $signed = $this->signed([
            'order_id' => $payment->transaction_ref,
            'status' => 'check',
        ]);

        $this->postJson(
            route('webhooks.billing', 'cryptomus'),
            $signed,
        )->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    /** Acknowledged so it stops being retried, but nothing is granted. */
    public function test_a_reference_we_never_issued_is_acknowledged(): void
    {
        $signed = $this->signed(['order_id' => 'SUB-NEVERISSUED', 'status' => 'paid']);

        $this->postJson(
            route('webhooks.billing', 'cryptomus'),
            $signed,
        )->assertOk();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    /** A reference belonging to a different gateway is not this one's to confirm. */
    public function test_a_reference_from_another_gateway_is_refused(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['gateway' => 'heleket']);

        $signed = $this->signed([
            'order_id' => $payment->transaction_ref,
            'status' => 'paid',
        ]);

        $this->postJson(
            route('webhooks.billing', 'cryptomus'),
            $signed,
        )->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_billing_webhook_needs_no_csrf_token(): void
    {
        $signed = $this->signed(['order_id' => 'SUB-WHATEVER', 'status' => 'paid']);

        $this->post(
            route('webhooks.billing', 'cryptomus'),
            $signed,
        )->assertOk();
    }
}
