<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlatformNumber;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\ActivatePurchase;
use App\Services\Billing\Checkout;
use App\Services\Numbers\RentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Taking a reseller's money and giving them what they paid for.
 *
 * The two rules that dominate: nothing is granted until the gateway confirms,
 * and applying a confirmed payment twice must not grant twice. Gateways retry
 * — that is normal, not an error.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        Plan::create([
            'code' => 'order_bot',
            'service_key' => ServiceKey::OrderBot,
            'name' => 'Order Bot',
            'price_monthly' => '20.00',
            'price_yearly' => '192.00',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        Plan::create([
            'code' => 'support_bot',
            'service_key' => ServiceKey::SupportBot,
            'name' => 'Support Bot',
            'price_monthly' => '15.00',
            'price_yearly' => '144.00',
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function checkout(): Checkout
    {
        return app(Checkout::class);
    }

    private function activate(): ActivatePurchase
    {
        return app(ActivatePurchase::class);
    }

    // ---- starting a checkout ---------------------------------------------

    public function test_a_checkout_records_what_was_bought_and_what_it_costs(): void
    {
        $payment = $this->checkout()->start(
            $this->tenant,
            ['order_bot', 'support_bot'],
            6,
            null,
            'cryptomus',
        );

        // 20x6 less 15% = 102, 15x6 less 15% = 76.50
        $this->assertSame('178.50', $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertCount(2, $payment->items);
    }

    public function test_a_pending_payment_grants_nothing(): void
    {
        // An abandoned checkout must not hand out a free month.
        $this->checkout()->start($this->tenant, ['order_bot'], 1, null, 'cryptomus');

        $this->assertFalse(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_every_payment_gets_its_own_reference(): void
    {
        // The reference is the webhook's only handle on the row.
        $first = $this->checkout()->start($this->tenant, ['order_bot'], 1, null, 'cryptomus');
        $second = $this->checkout()->start($this->tenant, ['order_bot'], 1, null, 'cryptomus');

        $this->assertNotSame($first->transaction_ref, $second->transaction_ref);
    }

    public function test_an_empty_cart_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->checkout()->start($this->tenant, [], 1, null, 'cryptomus');
    }

    public function test_a_number_without_a_bot_is_refused(): void
    {
        // A number with no bot on it answers nothing.
        $number = PlatformNumber::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->checkout()->start($this->tenant, [], 1, $number->id, 'cryptomus');
    }

    public function test_a_gateway_we_do_not_use_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->checkout()->start($this->tenant, ['order_bot'], 1, null, 'not-a-gateway');
    }

    public function test_a_number_taken_since_the_page_loaded_is_refused(): void
    {
        $number = PlatformNumber::factory()->rented()->create();

        $this->expectException(RuntimeException::class);

        $this->checkout()->start($this->tenant, ['order_bot'], 1, $number->id, 'cryptomus');
    }

    // ---- applying a paid invoice -----------------------------------------

    public function test_paying_activates_the_services(): void
    {
        $payment = $this->checkout()->start(
            $this->tenant,
            ['order_bot', 'support_bot'],
            3,
            null,
            'cryptomus',
        );

        $this->assertTrue($this->activate()->apply($payment));

        $this->assertTrue(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
        $this->assertTrue(Subscription::isServiceActive($this->tenant->id, ServiceKey::SupportBot));
    }

    public function test_the_term_sets_when_it_ends(): void
    {
        $payment = $this->checkout()->start($this->tenant, ['order_bot'], 3, null, 'cryptomus');

        $this->activate()->apply($payment);

        $subscription = Subscription::withoutTenantScope()->sole();

        $this->assertEqualsWithDelta(
            now()->addMonths(3)->timestamp,
            $subscription->ends_at->timestamp,
            60,
        );
    }

    public function test_a_retried_webhook_does_not_grant_twice(): void
    {
        // Gateways retry. The second delivery must be a quiet no-op, not
        // another three months.
        $payment = $this->checkout()->start($this->tenant, ['order_bot'], 3, null, 'cryptomus');

        $this->assertTrue($this->activate()->apply($payment));

        $endsAt = Subscription::withoutTenantScope()->sole()->ends_at;

        $this->assertFalse($this->activate()->apply($payment->fresh()));

        $this->assertSame(
            $endsAt->timestamp,
            Subscription::withoutTenantScope()->sole()->ends_at->timestamp,
        );
    }

    public function test_renewing_early_adds_to_the_time_already_paid_for(): void
    {
        // Someone who renews with a month left must not lose that month.
        Subscription::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->addMonth(),
        ]);

        $payment = $this->checkout()->start($this->tenant, ['order_bot'], 3, null, 'cryptomus');
        $this->activate()->apply($payment);

        $this->assertEqualsWithDelta(
            now()->addMonth()->addMonths(3)->timestamp,
            Subscription::withoutTenantScope()->sole()->ends_at->timestamp,
            60,
        );
    }

    public function test_a_lapsed_subscription_restarts_from_now(): void
    {
        // Those days are gone; back-dating the new term would hand over a
        // subscription that is already part-spent.
        Subscription::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subMonths(6),
            'ends_at' => now()->subMonths(3),
        ]);

        $payment = $this->checkout()->start($this->tenant, ['order_bot'], 1, null, 'cryptomus');
        $this->activate()->apply($payment);

        $this->assertEqualsWithDelta(
            now()->addMonth()->timestamp,
            Subscription::withoutTenantScope()->sole()->ends_at->timestamp,
            60,
        );
    }

    public function test_paying_out_of_sandbox_starts_the_term_from_now(): void
    {
        // Sandbox has no paid time to protect.
        Subscription::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Sandbox,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addYears(5),
        ]);

        $payment = $this->checkout()->start($this->tenant, ['order_bot'], 1, null, 'cryptomus');
        $this->activate()->apply($payment);

        $subscription = Subscription::withoutTenantScope()->sole();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertEqualsWithDelta(
            now()->addMonth()->timestamp,
            $subscription->ends_at->timestamp,
            60,
        );
    }

    // ---- buying a number alongside ---------------------------------------

    public function test_paying_hands_over_the_number_that_was_bought(): void
    {
        $number = PlatformNumber::factory()->create(['monthly_cost' => '15.00']);

        $payment = $this->checkout()->start(
            $this->tenant,
            ['order_bot'],
            1,
            $number->id,
            'cryptomus',
        );

        // 20 for the bot, 15 for the number, no discount on either.
        $this->assertSame('35.00', $payment->amount);

        $this->activate()->apply($payment);

        $this->assertSame('rented', $number->fresh()->status);
        $this->assertDatabaseHas('tenant_whatsapp', [
            'tenant_id' => $this->tenant->id,
            'phone_number_id' => $number->phone_number_id,
            'source' => 'rented',
            'bot_type' => 'order',
        ]);
    }

    public function test_a_number_bought_with_the_support_bot_runs_support(): void
    {
        $number = PlatformNumber::factory()->create();

        $payment = $this->checkout()->start(
            $this->tenant,
            ['support_bot'],
            1,
            $number->id,
            'cryptomus',
        );

        $this->activate()->apply($payment);

        $this->assertDatabaseHas('tenant_whatsapp', [
            'phone_number_id' => $number->phone_number_id,
            'bot_type' => 'support',
        ]);
    }

    public function test_a_number_lost_between_checkout_and_payment_rolls_the_whole_thing_back(): void
    {
        // The reseller has paid, so a half-applied invoice is the worst
        // outcome: some of it granted, no record that the rest is owed.
        $number = PlatformNumber::factory()->create();

        $payment = $this->checkout()->start(
            $this->tenant,
            ['order_bot'],
            1,
            $number->id,
            'cryptomus',
        );

        // Someone else takes it while the reseller is on the gateway's page.
        $other = Tenant::factory()->create();
        app(RentNumber::class)->claim($other, $number->id, 'order');

        try {
            $this->activate()->apply($payment);
            $this->fail('Applying a payment for a taken number should not succeed quietly.');
        } catch (RuntimeException) {
            // expected — it must surface, not be swallowed
        }

        // The order bot was in the same transaction, so it rolled back too.
        $this->assertFalse(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
    }

    // ---- isolation --------------------------------------------------------

    public function test_a_payment_only_activates_its_own_tenant(): void
    {
        $other = Tenant::factory()->create();

        $payment = $this->checkout()->start($this->tenant, ['order_bot'], 1, null, 'cryptomus');
        $this->activate()->apply($payment);

        $this->assertTrue(Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot));
        $this->assertFalse(Subscription::isServiceActive($other->id, ServiceKey::OrderBot));
    }
}
