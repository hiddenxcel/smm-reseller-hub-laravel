<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * isServiceActive() is the chokepoint every bot run and dashboard lock goes
 * through — if it wrongly returns true, an unpaid reseller's bot keeps
 * serving; if wrongly false, a paying one goes dark.
 */
class SubscriptionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_subscription_with_future_end_date_passes(): void
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->for($tenant)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addMonth(),
        ]);

        $this->assertTrue(Subscription::isServiceActive($tenant->id, ServiceKey::OrderBot));
    }

    public function test_active_subscription_with_no_end_date_passes(): void
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->for($tenant)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Active,
            'ends_at' => null,
        ]);

        $this->assertTrue(Subscription::isServiceActive($tenant->id, ServiceKey::OrderBot));
    }

    public function test_active_subscription_that_has_already_ended_fails(): void
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->for($tenant)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->subDay(),
        ]);

        $this->assertFalse(Subscription::isServiceActive($tenant->id, ServiceKey::OrderBot));
    }

    public function test_sandbox_does_not_pass_the_public_gate(): void
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->for($tenant)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Sandbox,
        ]);

        $this->assertFalse(Subscription::isServiceActive($tenant->id, ServiceKey::OrderBot));
        $this->assertTrue(Subscription::isSandbox($tenant->id, ServiceKey::OrderBot));
        $this->assertTrue(Subscription::isUsable($tenant->id, ServiceKey::OrderBot));
    }

    public function test_expired_and_cancelled_do_not_pass(): void
    {
        $tenant = Tenant::factory()->create();

        foreach ([SubscriptionStatus::Expired, SubscriptionStatus::Cancelled, SubscriptionStatus::Pending] as $status) {
            Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->delete();

            Subscription::factory()->for($tenant)->create([
                'service_key' => ServiceKey::OrderBot,
                'status' => $status,
                'ends_at' => now()->addMonth(),
            ]);

            $this->assertFalse(
                Subscription::isServiceActive($tenant->id, ServiceKey::OrderBot),
                "status {$status->value} must not pass the gate",
            );
        }
    }

    public function test_the_gate_is_per_service_not_per_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->for($tenant)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addMonth(),
        ]);

        $this->assertTrue(Subscription::isServiceActive($tenant->id, ServiceKey::OrderBot));
        $this->assertFalse(Subscription::isServiceActive($tenant->id, ServiceKey::SupportBot));
    }

    public function test_one_tenants_subscription_does_not_unlock_another(): void
    {
        $paying = Tenant::factory()->create();
        $freeloader = Tenant::factory()->create();

        Subscription::factory()->for($paying)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addMonth(),
        ]);

        $this->assertTrue(Subscription::isServiceActive($paying->id, ServiceKey::OrderBot));
        $this->assertFalse(Subscription::isServiceActive($freeloader->id, ServiceKey::OrderBot));
    }

    public function test_state_map_reports_the_tri_state(): void
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->for($tenant)->create([
            'service_key' => ServiceKey::OrderBot,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addMonth(),
        ]);

        Subscription::factory()->for($tenant)->create([
            'service_key' => ServiceKey::SupportBot,
            'status' => SubscriptionStatus::Sandbox,
        ]);

        $map = Subscription::stateMap($tenant->id);

        $this->assertSame('active', $map[ServiceKey::OrderBot->value]);
        $this->assertSame('sandbox', $map[ServiceKey::SupportBot->value]);
        $this->assertSame('locked', $map[ServiceKey::AiTickets->value]);
    }
}
