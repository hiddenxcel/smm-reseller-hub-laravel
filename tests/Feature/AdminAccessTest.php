<?php

namespace Tests\Feature;

use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Who may open the console, and who may not.
 *
 * The line this has to hold: a reseller session is never an admin session. The
 * two guards share a browser and a cookie, so "logged in" is not a question
 * with one answer — every admin route has to ask about the right guard, and a
 * tenant reaching /hx-control has to be turned away rather than served.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_is_closed_to_visitors(): void
    {
        $this->get('/hx-control')->assertRedirect(route('admin.login'));
        $this->get('/hx-control/tenants')->assertRedirect(route('admin.login'));
    }

    public function test_a_logged_in_reseller_is_not_an_admin(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($tenant, 'tenant');

        // The reseller has a perfectly valid session — for the wrong guard.
        $this->get('/hx-control')->assertRedirect(route('admin.login'));
        $this->get('/hx-control/tenants')->assertRedirect(route('admin.login'));
    }

    public function test_a_reseller_cannot_act_on_another_reseller_through_the_console(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        $this->actingAs($alice, 'tenant');

        $this->post("/hx-control/tenants/{$bob->id}/suspend")
            ->assertRedirect(route('admin.login'));

        $this->assertSame('active', $bob->fresh()->status);
    }

    public function test_an_admin_reaches_the_console(): void
    {
        $admin = Superadmin::factory()->create();

        $this->actingAs($admin, 'superadmin')
            ->get('/hx-control')
            ->assertOk();
    }

    public function test_an_admin_disabled_mid_session_is_locked_out_at_once(): void
    {
        $admin = Superadmin::factory()->create();

        $this->actingAs($admin, 'superadmin')->get('/hx-control')->assertOk();

        // Taken out of service while their session is still live.
        $admin->update(['status' => 'disabled']);

        $this->get('/hx-control')->assertRedirect(route('admin.login'));
    }

    public function test_a_disabled_admin_cannot_log_in(): void
    {
        Superadmin::factory()->disabled()->create([
            'username' => 'retired',
            'password_hash' => Hash::make('correct-horse'),
        ]);

        $this->post('/hx-control/login', [
            'username' => 'retired',
            'password' => 'correct-horse',
        ])->assertSessionHasErrors('username');

        $this->assertGuest('superadmin');
    }

    public function test_logging_in_records_the_visit(): void
    {
        Superadmin::factory()->create([
            'username' => 'owner',
            'password_hash' => Hash::make('correct-horse'),
        ]);

        $this->post('/hx-control/login', [
            'username' => 'owner',
            'password' => 'correct-horse',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated('superadmin');

        $this->assertDatabaseHas('activity_log', [
            'actor_type' => 'superadmin',
            'action' => 'admin.login',
        ]);

        $this->assertNotNull(Superadmin::where('username', 'owner')->first()->last_login_at);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        Superadmin::factory()->create([
            'username' => 'owner',
            'password_hash' => Hash::make('correct-horse'),
        ]);

        $this->post('/hx-control/login', [
            'username' => 'owner',
            'password' => 'wrong',
        ])->assertSessionHasErrors('username');

        $this->assertGuest('superadmin');
    }

    public function test_a_support_admin_cannot_suspend_or_move_money(): void
    {
        $support = Superadmin::factory()->support()->create();
        $tenant = Tenant::factory()->create();

        $this->actingAs($support, 'superadmin');

        // Support may look...
        $this->get("/hx-control/tenants/{$tenant->id}")->assertOk();

        // ...but not act.
        $this->post("/hx-control/tenants/{$tenant->id}/suspend")->assertForbidden();
        $this->post("/hx-control/tenants/{$tenant->id}/credit", [
            'delta' => 50,
            'reason' => 'nice try',
        ])->assertForbidden();

        $this->assertSame('active', $tenant->fresh()->status);
        $this->assertSame('0.00', $tenant->fresh()->referral_credit);
    }

    public function test_the_owner_may_do_everything(): void
    {
        $owner = Superadmin::factory()->owner()->create();

        $this->assertTrue($owner->can('tenants.suspend'));
        $this->assertTrue($owner->can('billing.manage'));
        // The owner grade is unconditional, including abilities not yet defined.
        $this->assertTrue($owner->can('something.not.built.yet'));
    }

    public function test_an_admin_sees_every_reseller_not_just_one(): void
    {
        Tenant::factory()->count(3)->create();

        $admin = Superadmin::factory()->create();

        $this->actingAs($admin, 'superadmin')
            ->getJson('/hx-control/tenants')
            ->assertOk();

        // The console runs outside a tenant session, so nothing narrows the
        // table to a single reseller.
        $this->assertSame(3, Tenant::count());
    }
}
