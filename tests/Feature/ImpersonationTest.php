<?php

namespace Tests\Feature;

use App\Models\AdminImpersonation;
use App\Models\BotCustomer;
use App\Models\Superadmin;
use App\Models\Tenant;
use App\Services\Admin\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An admin looking at the platform through a reseller's eyes.
 *
 * The line this has to hold: read-only means read-only, enforced by the server
 * and not by which buttons the screen happens to draw. During an impersonation
 * the admin holds a genuine tenant session, so every form that session could
 * normally post — a stale tab, a bookmarked URL, a replayed request — is a way
 * to change a reseller's data while wearing their identity. If the middleware
 * is wrong, an admin's mistake is recorded as the reseller's own action, and
 * the audit trail says the wrong thing about who did it.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->create();
        $this->tenant = Tenant::factory()->create(['business_name' => 'Kuza SMM']);
    }

    private function enter(?string $reason = 'ticket #482'): void
    {
        $this->actingAs($this->admin, 'superadmin')
            ->post("/hx-control/tenants/{$this->tenant->id}/impersonate", [
                'reason' => $reason,
            ]);
    }

    public function test_entering_an_account_opens_a_tenant_session_and_a_record(): void
    {
        $this->enter();

        $this->assertAuthenticatedAs($this->tenant, 'tenant');
        // The admin guard stays live too — that is what proves who is behind it.
        $this->assertAuthenticatedAs($this->admin, 'superadmin');

        $this->assertDatabaseHas('admin_impersonations', [
            'superadmin_id' => $this->admin->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'ticket #482',
            'ended_at' => null,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'actor_type' => 'superadmin',
            'action' => 'tenants.impersonate.start',
        ]);
    }

    public function test_the_reseller_dashboard_is_readable_while_impersonating(): void
    {
        $this->enter();

        $this->get('/dashboard')->assertOk();
        $this->get('/orders')->assertOk();
        $this->get('/customers')->assertOk();
    }

    public function test_every_write_is_refused_while_impersonating(): void
    {
        $customer = BotCustomer::factory()->for($this->tenant)->create([
            'name' => 'Original Name',
        ]);

        $this->enter();

        // A POST, a PATCH and a DELETE against three unrelated areas: the block
        // is on the method, so a route added tomorrow is covered without anyone
        // remembering to list it.
        $this->post(route('customers.store'), [
            'phone' => '255700000009',
            'name' => 'Injected',
        ])->assertRedirect();

        $this->patch(route('customers.update', $customer), [
            'name' => 'Changed By Admin',
        ])->assertRedirect();

        $this->delete(route('customers.destroy', $customer))->assertRedirect();

        // Nothing moved.
        $this->assertSame('Original Name', $customer->fresh()->name);
        $this->assertDatabaseMissing('bot_customers', ['name' => 'Injected']);
        $this->assertDatabaseHas('bot_customers', ['id' => $customer->id]);
    }

    public function test_a_blocked_write_says_why(): void
    {
        $this->enter();

        $this->from('/customers')
            ->post(route('customers.store'), ['phone' => '255700000009'])
            ->assertRedirect('/customers')
            ->assertSessionHas('error');
    }

    public function test_the_profile_cannot_be_changed_while_impersonating(): void
    {
        $this->enter();

        $this->patch(route('profile.update'), [
            'business_name' => 'Hijacked Ltd',
            'email' => 'attacker@example.com',
        ]);

        $this->assertSame('Kuza SMM', $this->tenant->fresh()->business_name);
    }

    public function test_the_console_is_unreachable_until_the_visit_ends(): void
    {
        $this->enter();

        // Admin screens read cross-tenant aggregates; with a tenant session live
        // those would narrow to the account being viewed and report one
        // reseller's numbers as the platform's.
        $this->get('/hx-control')->assertRedirect(route('dashboard'));
        $this->get('/hx-control/tenants')->assertRedirect(route('dashboard'));
    }

    public function test_stopping_closes_the_record_and_the_tenant_session(): void
    {
        $this->enter();

        $this->post(route('admin.impersonate.stop'))
            ->assertRedirect(route('admin.tenants.show', $this->tenant->id));

        $this->assertGuest('tenant');
        $this->assertAuthenticatedAs($this->admin, 'superadmin');

        $record = AdminImpersonation::first();
        $this->assertNotNull($record->ended_at);

        $this->assertDatabaseHas('activity_log', [
            'actor_type' => 'superadmin',
            'action' => 'tenants.impersonate.stop',
        ]);
    }

    public function test_the_console_works_again_after_stopping(): void
    {
        $this->enter();
        $this->post(route('admin.impersonate.stop'));

        $this->get('/hx-control')->assertOk();
    }

    public function test_writes_work_normally_for_the_reseller_themselves(): void
    {
        // The guard must not leak into ordinary sessions: a reseller who is not
        // being impersonated has to be able to run their own shop.
        $this->actingAs($this->tenant, 'tenant');

        $this->post(route('customers.store'), [
            'phone' => '255700000011',
            'name' => 'Their Own Customer',
        ]);

        $this->assertDatabaseHas('bot_customers', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Their Own Customer',
        ]);
    }

    public function test_a_second_account_cannot_be_entered_without_leaving_the_first(): void
    {
        $other = Tenant::factory()->create();

        $this->enter();

        // Starting a visit is a console action, and the console is closed while
        // one is open — so hopping straight from one account to another is
        // refused rather than silently swapping identities mid-session.
        $this->post("/hx-control/tenants/{$other->id}/impersonate")
            ->assertRedirect();

        $this->assertSame(1, AdminImpersonation::count());
        $this->assertDatabaseHas('admin_impersonations', [
            'tenant_id' => $this->tenant->id,
            'ended_at' => null,
        ]);
    }

    public function test_a_visit_left_open_is_closed_when_the_next_one_starts(): void
    {
        // A browser closed mid-visit leaves a row that never ends; the next
        // visit closes it, so "who was inside at 14:40" stays answerable.
        AdminImpersonation::create([
            'superadmin_id' => $this->admin->id,
            'tenant_id' => Tenant::factory()->create()->id,
            'started_at' => now()->subDay(),
        ]);

        $this->enter();

        $this->assertSame(1, AdminImpersonation::whereNull('ended_at')->count());
        $this->assertDatabaseHas('admin_impersonations', [
            'tenant_id' => $this->tenant->id,
            'ended_at' => null,
        ]);
    }

    public function test_logging_out_mid_visit_closes_it(): void
    {
        $this->enter();

        $this->post(route('admin.logout'));

        $this->assertGuest('tenant');
        $this->assertGuest('superadmin');
        $this->assertNotNull(AdminImpersonation::first()->ended_at);
    }

    public function test_an_admin_whose_role_forbids_it_cannot_enter(): void
    {
        $tenant = Tenant::factory()->create();

        // 'support' may impersonate; nothing below that exists yet, so the
        // check is exercised by removing the ability directly.
        $admin = Superadmin::factory()->create(['role' => 'support']);
        $this->assertTrue($admin->can('tenants.impersonate'));

        $this->actingAs($admin, 'superadmin')
            ->post("/hx-control/tenants/{$tenant->id}/impersonate")
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_reseller_cannot_start_an_impersonation(): void
    {
        $other = Tenant::factory()->create();

        $this->actingAs($this->tenant, 'tenant')
            ->post("/hx-control/tenants/{$other->id}/impersonate")
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseCount('admin_impersonations', 0);
    }

    public function test_the_impersonation_prop_is_shared_with_the_page(): void
    {
        $this->enter();

        $this->get('/dashboard')->assertInertia(
            fn ($page) => $page
                ->where('impersonation.tenant', 'Kuza SMM')
                ->where('impersonation.readOnly', true)
                ->where('auth.admin.username', $this->admin->username),
        );
    }

    public function test_an_ordinary_reseller_session_carries_no_impersonation_prop(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('impersonation', null));
    }

    public function test_a_session_key_pointing_at_nothing_still_ends_cleanly(): void
    {
        // Defensive: if the key survived without a matching row — a purged
        // table, a restored database — stopping must still drop the tenant
        // session rather than raising and stranding the admin inside it.
        $this->actingAs($this->admin, 'superadmin')
            ->withSession([Impersonation::SESSION_KEY => 999999])
            ->post(route('admin.impersonate.stop'))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertGuest('tenant');
        $this->assertAuthenticatedAs($this->admin, 'superadmin');
    }
}
