<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scariest class of bug in a multi-tenant platform: one reseller reading
 * or writing another's data. The old code relied on every raw query
 * remembering `WHERE tenant_id = ?`; these tests pin the global scope that
 * now enforces it.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_only_sees_its_own_records(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        BotCustomer::factory()->for($alice)->count(2)->create();
        BotCustomer::factory()->for($bob)->count(3)->create();

        $this->actingAs($alice, 'tenant');

        $this->assertCount(2, BotCustomer::all());
        $this->assertTrue(BotCustomer::all()->every(fn ($c) => $c->tenant_id === $alice->id));
    }

    public function test_a_tenant_cannot_read_another_tenants_record_by_id(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        $bobsCustomer = BotCustomer::factory()->for($bob)->create();

        $this->actingAs($alice, 'tenant');

        $this->assertNull(BotCustomer::find($bobsCustomer->id));
    }

    public function test_a_tenant_cannot_update_another_tenants_record(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        $bobsCustomer = BotCustomer::factory()->for($bob)->create(['name' => 'Bob Customer']);

        $this->actingAs($alice, 'tenant');

        $affected = BotCustomer::whereKey($bobsCustomer->id)->update(['name' => 'Hijacked']);

        $this->assertSame(0, $affected);
        $this->assertSame('Bob Customer', $bobsCustomer->fresh()->name);
    }

    public function test_a_tenant_cannot_delete_another_tenants_record(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        $bobsCustomer = BotCustomer::factory()->for($bob)->create();

        $this->actingAs($alice, 'tenant');

        BotCustomer::whereKey($bobsCustomer->id)->delete();

        $this->assertDatabaseHas('bot_customers', ['id' => $bobsCustomer->id]);
    }

    public function test_tenant_id_is_stamped_from_the_session(): void
    {
        $alice = Tenant::factory()->create();

        $this->actingAs($alice, 'tenant');

        $customer = new BotCustomer(['phone' => '255700000001', 'name' => 'X']);
        $customer->save();

        $this->assertSame($alice->id, $customer->tenant_id);
    }

    public function test_without_tenant_scope_crosses_tenants_for_webhooks_and_cron(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        BotCustomer::factory()->for($alice)->create();
        BotCustomer::factory()->for($bob)->create();

        $this->actingAs($alice, 'tenant');

        $this->assertCount(1, BotCustomer::all());
        $this->assertCount(2, BotCustomer::withoutTenantScope()->get());
    }

    public function test_no_tenant_session_means_no_scoping(): void
    {
        // Cron and webhooks run with no session; they are expected to be
        // explicit about the tenant they act on.
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        BotCustomer::factory()->for($alice)->create();
        BotCustomer::factory()->for($bob)->create();

        $this->assertCount(2, BotCustomer::all());
    }
}
