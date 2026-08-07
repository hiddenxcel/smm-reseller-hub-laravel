<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The price list.
 *
 * The line this has to hold: **editing a plan never changes what someone is
 * already paying.** A subscription carries its own ends_at, priced at the
 * moment of purchase, so a price rise applies to the next renewal and to
 * nothing else. If that ever stops being true, every reseller on the platform
 * silently gets a different bill than the one they agreed to.
 */
class AdminPlansTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->actingAs($this->admin, 'superadmin');
    }

    public function test_the_page_lists_every_plan(): void
    {
        Plan::factory()->count(3)->create();

        $this->get('/hx-control/plans')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Plans/Index')
                ->has('plans', 3),
        );
    }

    public function test_a_plan_can_be_created(): void
    {
        $this->post('/hx-control/plans', $this->payload())->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'code' => 'order-bot-standard',
            'name' => 'Order Bot',
            'price_monthly' => 17.00,
        ]);

        $this->assertDatabaseHas('activity_log', ['action' => 'plans.create']);
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        Plan::factory()->create(['code' => 'taken']);

        $this->post('/hx-control/plans', $this->payload(['code' => 'taken']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_price_change_leaves_live_subscriptions_alone(): void
    {
        $plan = Plan::factory()->create(['price_monthly' => 17.00]);
        $tenant = Tenant::factory()->create();

        $subscription = Subscription::factory()
            ->for($tenant)
            ->active()
            ->create(['plan_id' => $plan->id]);

        $endsAt = $subscription->ends_at;

        $this->patch("/hx-control/plans/{$plan->id}", $this->payload([
            'code' => $plan->code,
            'price_monthly' => 49.00,
        ]))->assertRedirect();

        $this->assertSame('49.00', $plan->fresh()->price_monthly);

        // The reseller's subscription is untouched: same end date, same status.
        $fresh = $subscription->fresh();
        $this->assertEquals($endsAt->toIso8601String(), $fresh->ends_at->toIso8601String());
        $this->assertSame('active', $fresh->status->value);
    }

    public function test_a_price_change_records_how_many_resellers_it_will_reach(): void
    {
        $plan = Plan::factory()->create(['price_monthly' => 17.00]);

        Subscription::factory()->count(2)->active()->create(['plan_id' => $plan->id]);

        $this->patch("/hx-control/plans/{$plan->id}", $this->payload([
            'code' => $plan->code,
            'price_monthly' => 20.00,
        ]));

        $entry = ActivityLog::where('action', 'plans.update')->first();

        $this->assertSame(2, $entry->details['live_subscriptions']);
    }

    public function test_retiring_takes_a_plan_off_sale_without_deleting_it(): void
    {
        $plan = Plan::factory()->create(['service_key' => ServiceKey::OrderBot]);

        $this->post("/hx-control/plans/{$plan->id}/retire")->assertRedirect();

        $this->assertSame('inactive', $plan->fresh()->status);
        // The row survives, because payments and subscriptions point at it.
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        // And it drops out of what checkout offers.
        $this->assertNull(Plan::forService(ServiceKey::OrderBot));
    }

    public function test_a_retired_plan_can_be_put_back_on_sale(): void
    {
        $plan = Plan::factory()->create(['status' => 'inactive']);

        $this->post("/hx-control/plans/{$plan->id}/restore");

        $this->assertSame('active', $plan->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['action' => 'plans.restore']);
    }

    public function test_retiring_leaves_existing_subscriptions_running(): void
    {
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->active()->create(['plan_id' => $plan->id]);

        $this->post("/hx-control/plans/{$plan->id}/retire");

        // Taking a plan off sale stops new purchases; it does not switch off
        // the resellers already on it.
        $this->assertSame('active', $subscription->fresh()->status->value);
        $this->assertTrue(Subscription::isServiceActive(
            $subscription->tenant_id,
            $subscription->service_key,
        ));
    }

    public function test_the_page_reports_how_many_are_on_each_plan(): void
    {
        $plan = Plan::factory()->create();
        Subscription::factory()->count(3)->active()->create(['plan_id' => $plan->id]);
        // An expired one must not be counted as live.
        Subscription::factory()->create([
            'plan_id' => $plan->id,
            'status' => 'expired',
        ]);

        $this->get('/hx-control/plans')->assertInertia(
            fn (AssertableInertia $page) => $page->where('plans.0.liveSubscriptions', 3),
        );
    }

    public function test_a_support_admin_can_look_but_not_edit(): void
    {
        $plan = Plan::factory()->create();
        $support = Superadmin::factory()->support()->create();

        $this->actingAs($support, 'superadmin');

        $this->get('/hx-control/plans')->assertOk();

        $this->patch("/hx-control/plans/{$plan->id}", $this->payload([
            'code' => $plan->code,
            'price_monthly' => 999,
        ]))->assertForbidden();

        $this->post("/hx-control/plans/{$plan->id}/retire")->assertForbidden();

        $this->assertSame('active', $plan->fresh()->status);
    }

    public function test_a_reseller_cannot_reach_the_price_list(): void
    {
        // setUp() logged an admin in, and logging a tenant in does not log them
        // out — both guards stay live, exactly as during an impersonation. The
        // admin session is cleared first so this tests a reseller alone.
        Auth::guard('superadmin')->logout();

        $this->actingAs(Tenant::factory()->create(), 'tenant');

        $this->get('/hx-control/plans')->assertRedirect(route('admin.login'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'order-bot-standard',
            'name' => 'Order Bot',
            'description' => 'Sells in chat.',
            'service_key' => 'order_bot',
            'price_monthly' => 17.00,
            'price_yearly' => 163.20,
            'currency' => 'USD',
            'max_panels' => 5,
            'max_numbers' => 1,
            'max_orders_monthly' => 1000,
            'max_messages_monthly' => 5000,
            'max_refills_monthly' => 100,
            'status' => 'active',
            'sort_order' => 1,
            ...$overrides,
        ];
    }
}
