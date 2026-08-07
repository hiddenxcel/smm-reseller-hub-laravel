<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Superadmin;
use App\Models\Tenant;
use App\Services\Admin\PaymentFilters;
use App\Services\Admin\PaymentQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Settling payments the automatic path could not.
 *
 * This is the most consequential screen in the console: confirming grants a
 * real subscription for real money, from a button rather than from a verified
 * gateway signature. Three lines have to hold.
 *
 * **Confirming must go through ActivatePurchase.** Setting status = 'success'
 * by hand would mark the payment paid and switch nothing on, leaving a reseller
 * who has paid with no service and no error anywhere.
 *
 * **It must be idempotent.** A webhook arriving a second after an admin clicks
 * confirm must not grant a second month. ActivatePurchase's compare-and-swap is
 * what prevents that, which is exactly why this path is not allowed to bypass
 * it.
 *
 * **Marking failed must not take away what was already granted.** That would be
 * this screen quietly reversing a transaction the gateway still believes in.
 */
class AdminPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->tenant = Tenant::factory()->create(['business_name' => 'Kuza SMM']);

        Plan::factory()->create([
            'service_key' => ServiceKey::OrderBot,
            'price_monthly' => 17.00,
        ]);

        $this->actingAs($this->admin, 'superadmin');
    }

    private function payment(array $overrides = []): SubscriptionPayment
    {
        return SubscriptionPayment::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => 'cryptomus',
            'transaction_ref' => 'REF-'.fake()->unique()->numerify('######'),
            'amount' => 17.00,
            'currency' => 'USD',
            'months' => 1,
            'status' => 'pending',
            'items' => [
                ['type' => 'service', 'key' => 'order_bot', 'months' => 1],
            ],
            ...$overrides,
        ]);
    }

    public function test_the_list_shows_payments_to_the_platform(): void
    {
        $this->payment();
        $this->payment();

        $this->get('/hx-control/payments')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Payments/Index')
                ->has('payments.data', 2)
                ->where('payments.data.0.tenant', 'Kuza SMM'),
        );
    }

    public function test_a_payment_can_be_found_by_its_reference(): void
    {
        $this->payment(['transaction_ref' => 'FINDME-001']);
        $this->payment(['transaction_ref' => 'OTHER-002']);

        $this->get('/hx-control/payments?q=FINDME')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.reference', 'FINDME-001'),
        );
    }

    public function test_the_detail_endpoint_returns_the_gateway_response(): void
    {
        $payment = $this->payment(['raw_response' => '{"status":"waiting"}']);

        $this->getJson("/hx-control/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('payment.rawResponse', '{"status":"waiting"}');
    }

    public function test_confirming_grants_the_subscription_that_was_bought(): void
    {
        $payment = $this->payment();

        $this->assertFalse(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );

        $this->post("/hx-control/payments/{$payment->id}/confirm", [
            'reason' => 'confirmed in Cryptomus dashboard',
        ])->assertRedirect();

        $this->assertSame('success', $payment->fresh()->status);

        // The whole point: the reseller can actually use what they paid for.
        $this->assertTrue(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );
    }

    public function test_confirming_is_recorded_with_the_reason(): void
    {
        $payment = $this->payment();

        $this->post("/hx-control/payments/{$payment->id}/confirm", [
            'reason' => 'bank transfer seen, ref 8841',
        ]);

        $entry = ActivityLog::where('action', 'payments.confirm')->first();

        $this->assertSame($this->tenant->id, $entry->details['tenant_id']);
        $this->assertSame('bank transfer seen, ref 8841', $entry->details['reason']);
        $this->assertTrue($entry->details['applied']);
    }

    public function test_confirming_twice_grants_only_one_term(): void
    {
        $payment = $this->payment();

        $this->post("/hx-control/payments/{$payment->id}/confirm", ['reason' => 'first']);

        $endsAt = Subscription::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->first()
            ->ends_at;

        // A second click, or a webhook landing right after the first.
        $this->post("/hx-control/payments/{$payment->id}/confirm", ['reason' => 'again'])
            ->assertSessionHas('error');

        $this->assertEquals(
            $endsAt->toIso8601String(),
            Subscription::withoutTenantScope()
                ->where('tenant_id', $this->tenant->id)
                ->first()
                ->ends_at
                ->toIso8601String(),
        );

        $this->assertSame(
            1,
            Subscription::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count(),
        );
    }

    public function test_confirming_requires_a_reason(): void
    {
        $payment = $this->payment();

        $this->post("/hx-control/payments/{$payment->id}/confirm")
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );
    }

    public function test_a_pending_payment_can_be_marked_failed(): void
    {
        $payment = $this->payment();

        $this->post("/hx-control/payments/{$payment->id}/fail", [
            'reason' => 'customer abandoned checkout',
        ])->assertRedirect();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['action' => 'payments.fail']);

        // Failing records that it never completed; it grants nothing.
        $this->assertFalse(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );
    }

    public function test_a_successful_payment_cannot_be_marked_failed(): void
    {
        $payment = $this->payment();

        $this->post("/hx-control/payments/{$payment->id}/confirm", ['reason' => 'paid']);

        $this->post("/hx-control/payments/{$payment->id}/fail", ['reason' => 'undo'])
            ->assertSessionHas('error');

        // The subscription it granted is still live, so the payment record must
        // not start claiming it was never paid.
        $this->assertSame('success', $payment->fresh()->status);
        $this->assertTrue(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );
    }

    public function test_a_successful_payment_can_be_re_applied(): void
    {
        // The narrow real case: marked successful, but activation did not
        // finish, so the reseller has nothing.
        $payment = $this->payment(['status' => 'success']);

        $this->assertFalse(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );

        $this->post("/hx-control/payments/{$payment->id}/reapply", [
            'reason' => 'activation failed on the number, services never switched on',
        ])->assertRedirect();

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertTrue(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );
    }

    public function test_a_pending_payment_cannot_be_re_applied(): void
    {
        $payment = $this->payment();

        $this->post("/hx-control/payments/{$payment->id}/reapply", ['reason' => 'x'])
            ->assertSessionHas('error');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_list_flags_a_payment_that_is_stuck(): void
    {
        $payment = $this->payment();

        // Written after creation: Eloquent stamps created_at itself, so passing
        // it to create() is overwritten with "now".
        $payment->forceFill(['created_at' => now()->subHours(5)])->save();

        $this->get('/hx-control/payments?status=pending')->assertInertia(
            fn (AssertableInertia $page) => $page->where('payments.data.0.stale', true),
        );
    }

    public function test_a_support_admin_cannot_settle_payments(): void
    {
        $payment = $this->payment();
        $support = Superadmin::factory()->support()->create();

        $this->actingAs($support, 'superadmin');

        $this->get('/hx-control/payments')->assertOk();

        $this->post("/hx-control/payments/{$payment->id}/confirm", ['reason' => 'nope'])
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );
    }

    public function test_a_reseller_cannot_reach_payments(): void
    {
        $payment = $this->payment();

        // setUp() logged an admin in, and logging a tenant in does not log them
        // out — both guards stay live, exactly as during an impersonation. The
        // admin session is cleared first so this tests a reseller alone.
        Auth::guard('superadmin')->logout();

        $this->actingAs($this->tenant, 'tenant');

        $this->get('/hx-control/payments')->assertRedirect(route('admin.login'));
        $this->post("/hx-control/payments/{$payment->id}/confirm", ['reason' => 'free money'])
            ->assertRedirect(route('admin.login'));

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_totals_count_only_successful_payments(): void
    {
        $this->payment(['status' => 'success', 'amount' => 20.00]);
        $this->payment(['status' => 'pending', 'amount' => 50.00]);
        $this->payment(['status' => 'failed', 'amount' => 99.00]);

        // Asserted against the query rather than the page: `totals` is a
        // deferred prop, so it is absent from the first response by design, and
        // reproducing Inertia's follow-up request here would test the framework
        // rather than the sums.
        $totals = PaymentQuery::make()->totals(new PaymentFilters);

        $this->assertSame(20.0, $totals['collected']);
        $this->assertSame(50.0, $totals['pendingValue']);
    }
}
