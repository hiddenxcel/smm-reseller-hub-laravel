<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\ActivityLog;
use App\Models\Subscription;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Granting and taking away access by hand.
 *
 * Two lines this has to hold:
 *
 * Extending must add to what is left, never replace it — an admin adding a
 * month to a subscription with three weeks remaining must not shorten it to
 * one. That is the sort of bug nobody reports until a reseller's shop stops.
 *
 * Cancelling must actually cut access off. Subscription::isServiceActive() is
 * the gate every bot run goes through, and it reads both status and ends_at, so
 * a cancelled row with a future ends_at would keep serving somebody who has
 * been told they are switched off.
 */
class AdminSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->tenant = Tenant::factory()->create(['business_name' => 'Kuza SMM']);

        $this->actingAs($this->admin, 'superadmin');
    }

    public function test_the_list_shows_every_subscription(): void
    {
        Subscription::factory()->count(3)->active()->create();

        $this->get('/hx-control/subscriptions')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Subscriptions/Index')
                ->has('subscriptions.data', 3),
        );
    }

    public function test_the_list_carries_the_reseller_name(): void
    {
        Subscription::factory()->for($this->tenant)->active()->create();

        $this->get('/hx-control/subscriptions')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('subscriptions.data.0.tenant', 'Kuza SMM'),
        );
    }

    public function test_a_lapsed_row_reads_as_expired_not_active(): void
    {
        // status says active, the date says otherwise — the gate believes the
        // date, and so must this screen.
        Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);

        $this->get('/hx-control/subscriptions')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('subscriptions.data.0.state', 'expired'),
        );
    }

    public function test_a_subscription_ending_within_a_week_reads_as_expiring(): void
    {
        Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(3),
        ]);

        $this->get('/hx-control/subscriptions?state=expiring')->assertInertia(
            fn (AssertableInertia $page) => $page->has('subscriptions.data', 1),
        );
    }

    public function test_a_sandbox_subscription_reads_as_trial(): void
    {
        Subscription::factory()->sandbox()->create();

        $this->get('/hx-control/subscriptions?state=trial')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.state', 'trial'),
        );
    }

    public function test_extending_adds_to_the_time_that_is_left(): void
    {
        $subscription = Subscription::factory()->for($this->tenant)->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ]);

        $this->post("/hx-control/subscriptions/{$subscription->id}/extend", [
            'months' => 1,
            'reason' => 'paid by bank transfer',
        ])->assertRedirect();

        // 20 days remaining + 1 month, not 1 month from today.
        $this->assertEqualsWithDelta(
            now()->addDays(20)->addMonth()->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            60,
        );
    }

    public function test_extending_a_lapsed_subscription_starts_from_today(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(3),
            'ends_at' => now()->subMonth(),
        ]);

        $this->post("/hx-control/subscriptions/{$subscription->id}/extend", [
            'months' => 1,
            'reason' => 'goodwill after outage',
        ]);

        // Those days are gone; counting from the old ends_at would grant less
        // than the admin thinks they are granting.
        $this->assertEqualsWithDelta(
            now()->addMonth()->timestamp,
            $subscription->fresh()->ends_at->timestamp,
            60,
        );
    }

    public function test_extending_activates_and_is_recorded(): void
    {
        $subscription = Subscription::factory()->for($this->tenant)->sandbox()->create();

        $this->post("/hx-control/subscriptions/{$subscription->id}/extend", [
            'months' => 2,
            'reason' => 'comped for launch partner',
        ]);

        $fresh = $subscription->fresh();
        $this->assertSame('active', $fresh->status->value);

        $entry = ActivityLog::where('action', 'subscriptions.extend')->first();
        $this->assertSame($this->tenant->id, $entry->details['tenant_id']);
        $this->assertSame(2, $entry->details['months']);
        $this->assertSame('comped for launch partner', $entry->details['reason']);
    }

    public function test_extending_requires_a_reason(): void
    {
        $subscription = Subscription::factory()->active()->create();
        $before = $subscription->ends_at;

        $this->post("/hx-control/subscriptions/{$subscription->id}/extend", [
            'months' => 1,
        ])->assertSessionHasErrors('reason');

        $this->assertEquals(
            $before->toIso8601String(),
            $subscription->fresh()->ends_at->toIso8601String(),
        );
    }

    public function test_cancelling_actually_cuts_access_off(): void
    {
        $subscription = Subscription::factory()->for($this->tenant)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(6),
        ]);

        $this->assertTrue(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );

        $this->post("/hx-control/subscriptions/{$subscription->id}/cancel", [
            'reason' => 'chargeback',
        ])->assertRedirect();

        // Status AND ends_at both moved: the gate reads both, and a future
        // ends_at would keep the bot serving.
        $fresh = $subscription->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertTrue($fresh->ends_at->lessThanOrEqualTo(now()->addSecond()));

        $this->assertFalse(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );
    }

    public function test_a_cancelled_subscription_can_be_reinstated(): void
    {
        $subscription = Subscription::factory()->for($this->tenant)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => now()->subDay(),
        ]);

        $this->post("/hx-control/subscriptions/{$subscription->id}/reinstate", [
            'months' => 3,
            'reason' => 'cancelled in error',
        ])->assertRedirect();

        $fresh = $subscription->fresh();
        $this->assertSame('active', $fresh->status->value);
        $this->assertTrue(
            Subscription::isServiceActive($this->tenant->id, ServiceKey::OrderBot),
        );

        $this->assertDatabaseHas('activity_log', ['action' => 'subscriptions.reinstate']);
    }

    public function test_auto_renew_can_be_toggled(): void
    {
        $subscription = Subscription::factory()->active()->create(['auto_renew' => false]);

        $this->post("/hx-control/subscriptions/{$subscription->id}/auto-renew", ['on' => '1']);

        $this->assertTrue($subscription->fresh()->auto_renew);

        $this->post("/hx-control/subscriptions/{$subscription->id}/auto-renew", ['on' => '0']);

        $this->assertFalse($subscription->fresh()->auto_renew);
    }

    public function test_a_support_admin_cannot_grant_or_remove_access(): void
    {
        $subscription = Subscription::factory()->active()->create();
        $support = Superadmin::factory()->support()->create();

        $this->actingAs($support, 'superadmin');

        $this->get('/hx-control/subscriptions')->assertOk();

        $this->post("/hx-control/subscriptions/{$subscription->id}/extend", [
            'months' => 12,
            'reason' => 'nice try',
        ])->assertForbidden();

        $this->post("/hx-control/subscriptions/{$subscription->id}/cancel", [
            'reason' => 'nice try',
        ])->assertForbidden();

        $this->assertSame('active', $subscription->fresh()->status->value);
    }

    public function test_a_reseller_cannot_reach_the_list(): void
    {
        // See the note in AdminPlansTest: setUp() left an admin logged in, and
        // logging a tenant in does not log them out.
        Auth::guard('superadmin')->logout();

        $this->actingAs($this->tenant, 'tenant');

        $this->get('/hx-control/subscriptions')->assertRedirect(route('admin.login'));
    }
}
