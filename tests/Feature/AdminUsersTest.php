<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AdminImpersonation;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Managing the people who can operate the console.
 *
 * The line this has to hold: **the console can never be locked out of itself.**
 * Only an owner can create admins or change roles, so disabling or demoting the
 * last active owner would leave a platform nobody can administer — recoverable
 * only by editing the database by hand at whatever hour it is discovered.
 *
 * The second line: only an owner may be here at all. A grade that can grant
 * itself a higher grade is not a grade.
 */
class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = Superadmin::factory()->owner()->create(['username' => 'kuza']);
        $this->actingAs($this->owner, 'superadmin');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'username' => 'newadmin',
            'name' => 'New Admin',
            'email' => 'new@example.com',
            'role' => 'support',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            ...$overrides,
        ];
    }

    public function test_the_list_shows_every_admin(): void
    {
        Superadmin::factory()->count(2)->create();

        $this->get('/hx-control/admins')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                ->has('admins', 3),
        );
    }

    public function test_an_admin_can_be_created(): void
    {
        $this->post('/hx-control/admins', $this->payload())->assertRedirect();

        $created = Superadmin::where('username', 'newadmin')->first();

        $this->assertNotNull($created);
        $this->assertSame('support', $created->role);
        $this->assertSame('active', $created->status);
        $this->assertTrue(Hash::check('a-long-enough-password', $created->password_hash));

        $this->assertDatabaseHas('activity_log', ['action' => 'admins.create']);
    }

    public function test_a_created_admins_password_is_never_recorded(): void
    {
        $this->post('/hx-control/admins', $this->payload());

        $entry = ActivityLog::where('action', 'admins.create')->first();

        // An audit trail carrying credentials is a breach waiting to be read.
        $this->assertStringNotContainsString(
            'a-long-enough-password',
            json_encode($entry->details),
        );
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->post('/hx-control/admins', $this->payload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('superadmins', ['username' => 'newadmin']);
    }

    public function test_a_duplicate_username_is_refused(): void
    {
        $this->post('/hx-control/admins', $this->payload(['username' => 'kuza']))
            ->assertSessionHasErrors('username');
    }

    public function test_an_admin_can_be_disabled_and_enabled(): void
    {
        $other = Superadmin::factory()->create();

        $this->post("/hx-control/admins/{$other->id}/disable")->assertRedirect();
        $this->assertSame('disabled', $other->fresh()->status);
        // The row survives: every audit entry points at an id.
        $this->assertDatabaseHas('superadmins', ['id' => $other->id]);

        $this->post("/hx-control/admins/{$other->id}/enable");
        $this->assertSame('active', $other->fresh()->status);
    }

    public function test_disabling_an_admin_closes_any_visit_they_left_open(): void
    {
        $other = Superadmin::factory()->create();
        $tenant = Tenant::factory()->create();

        AdminImpersonation::create([
            'superadmin_id' => $other->id,
            'tenant_id' => $tenant->id,
            'started_at' => now()->subHour(),
        ]);

        $this->post("/hx-control/admins/{$other->id}/disable");

        // Their session is about to stop working; an open row would read as
        // somebody still inside a reseller's account.
        $this->assertNotNull(AdminImpersonation::first()->ended_at);
    }

    public function test_the_last_owner_cannot_be_disabled(): void
    {
        // No other owner exists — disabling this one leaves nobody who can
        // create admins or change roles.
        $this->post("/hx-control/admins/{$this->owner->id}/disable")
            ->assertSessionHas('error');

        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $this->patch("/hx-control/admins/{$this->owner->id}", [
            'name' => 'Kuza',
            'email' => null,
            'role' => 'support',
        ])->assertSessionHas('error');

        $this->assertSame('owner', $this->owner->fresh()->role);
    }

    public function test_an_owner_can_be_demoted_when_another_owner_remains(): void
    {
        Superadmin::factory()->owner()->create();

        $this->patch("/hx-control/admins/{$this->owner->id}", [
            'name' => 'Kuza',
            'email' => null,
            'role' => 'admin',
        ])->assertRedirect();

        $this->assertSame('admin', $this->owner->fresh()->role);
    }

    public function test_a_disabled_owner_does_not_count_as_a_way_back_in(): void
    {
        // A second owner exists but is disabled, so this one is still the last
        // active way into the console.
        Superadmin::factory()->owner()->disabled()->create();

        $this->post("/hx-control/admins/{$this->owner->id}/disable")
            ->assertSessionHas('error');

        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_an_admin_cannot_disable_themselves(): void
    {
        Superadmin::factory()->owner()->create();

        $this->post("/hx-control/admins/{$this->owner->id}/disable")
            ->assertSessionHas('error');

        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_a_password_can_be_set_for_another_admin(): void
    {
        $other = Superadmin::factory()->create();

        $this->post("/hx-control/admins/{$other->id}/password", [
            'password' => 'another-long-password',
            'password_confirmation' => 'another-long-password',
        ])->assertRedirect();

        $this->assertTrue(
            Hash::check('another-long-password', $other->fresh()->password_hash),
        );

        $entry = ActivityLog::where('action', 'admins.password')->first();
        $this->assertFalse($entry->details['self']);
        $this->assertStringNotContainsString(
            'another-long-password',
            json_encode($entry->details),
        );
    }

    public function test_a_non_owner_cannot_manage_admins(): void
    {
        $admin = Superadmin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'superadmin');

        // 'admin' can run resellers and billing, but promoting itself would
        // make the grade meaningless.
        $this->get('/hx-control/admins')->assertForbidden();
        $this->post('/hx-control/admins', $this->payload())->assertForbidden();
        $this->patch("/hx-control/admins/{$this->owner->id}", [
            'name' => 'x',
            'email' => null,
            'role' => 'support',
        ])->assertForbidden();

        $this->assertDatabaseMissing('superadmins', ['username' => 'newadmin']);
        $this->assertSame('owner', $this->owner->fresh()->role);
    }

    public function test_a_support_admin_cannot_manage_admins(): void
    {
        $support = Superadmin::factory()->support()->create();

        $this->actingAs($support, 'superadmin');

        $this->get('/hx-control/admins')->assertForbidden();
    }

    public function test_a_reseller_cannot_reach_admin_users(): void
    {
        Auth::guard('superadmin')->logout();

        $this->actingAs(Tenant::factory()->create(), 'tenant');

        $this->get('/hx-control/admins')->assertRedirect(route('admin.login'));
        $this->post('/hx-control/admins', $this->payload())
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseMissing('superadmins', ['username' => 'newadmin']);
    }
}
