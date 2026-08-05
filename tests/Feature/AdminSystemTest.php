<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BlockedIp;
use App\Models\PlatformSetting;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Settings, security, the audit trail and backups.
 *
 * Three lines matter here.
 *
 * **Gateway credentials never enter the database.** They stay in the server
 * environment; the Settings screen reports whether each is configured and
 * nothing more. A key in the database is a key in every backup and behind a
 * session cookie rather than behind server access.
 *
 * **The audit trail cannot be edited or deleted.** There is no route for it —
 * not a forbidden one, none at all — because a trail somebody can quietly tidy
 * is not a trail.
 *
 * **An admin cannot block themselves out.** Blocking the address you are
 * sitting behind loses you the console, and the fix is a database edit.
 */
class AdminSystemTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = Superadmin::factory()->owner()->create();
        $this->actingAs($this->owner, 'superadmin');
    }

    private function settingsPayload(array $overrides = []): array
    {
        return [
            'company_name' => 'HiddenXcel',
            'support_email' => 'help@example.com',
            'support_whatsapp' => '255700000000',
            'website_url' => 'https://example.com',
            'terms_url' => '',
            'privacy_url' => '',
            'referral_percent' => 10,
            'registration_open' => true,
            ...$overrides,
        ];
    }

    // ---- settings --------------------------------------------------------

    public function test_settings_fall_back_to_defaults_before_anything_is_saved(): void
    {
        $this->get('/hx-control/settings')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/System/Settings')
                ->where('settings.company_name', PlatformSetting::DEFAULTS['company_name']),
        );
    }

    public function test_settings_can_be_saved(): void
    {
        $this->post('/hx-control/settings', $this->settingsPayload([
            'company_name' => 'Kuza Group',
        ]))->assertRedirect();

        $this->assertSame('Kuza Group', PlatformSetting::get('company_name'));
        $this->assertDatabaseHas('activity_log', ['action' => 'settings.update']);
    }

    public function test_saving_records_only_what_changed(): void
    {
        $this->post('/hx-control/settings', $this->settingsPayload());

        ActivityLog::query()->delete();

        $this->post('/hx-control/settings', $this->settingsPayload([
            'company_name' => 'Renamed',
        ]));

        $entry = ActivityLog::where('action', 'settings.update')->first();

        $this->assertSame(['company_name'], $entry->details['changed']);
    }

    public function test_registration_can_be_closed_without_a_deploy(): void
    {
        $this->post('/hx-control/settings', $this->settingsPayload([
            'registration_open' => false,
        ]));

        $this->assertFalse(PlatformSetting::get('registration_open'));
    }

    public function test_the_settings_screen_reports_gateways_without_exposing_keys(): void
    {
        config(['services.billing.cryptomus.api_key' => 'secret-key-value']);
        config(['services.billing.cryptomus.merchant_id' => 'merchant-uuid']);

        $response = $this->get('/hx-control/settings');

        $response->assertInertia(function (AssertableInertia $page) {
            $gateways = collect($page->toArray()['props']['gateways']);
            $cryptomus = $gateways->firstWhere('code', 'cryptomus');

            $this->assertTrue($cryptomus['configured']);
            // Reported as configured; the key itself never crosses the wire.
            $this->assertArrayNotHasKey('api_key', $cryptomus);
        });

        $response->assertDontSee('secret-key-value');
    }

    public function test_an_invalid_url_is_refused(): void
    {
        $this->post('/hx-control/settings', $this->settingsPayload([
            'website_url' => 'not-a-url',
        ]))->assertSessionHasErrors('website_url');
    }

    // ---- security --------------------------------------------------------

    public function test_an_address_can_be_blocked_and_unblocked(): void
    {
        $this->post('/hx-control/security/block', [
            'ip' => '203.0.113.4',
            'reason' => 'credential stuffing',
        ])->assertRedirect();

        $this->assertDatabaseHas('blocked_ips', ['ip' => '203.0.113.4']);
        $this->assertTrue(BlockedIp::blocks('203.0.113.4'));
        $this->assertDatabaseHas('activity_log', ['action' => 'security.block_ip']);

        $blocked = BlockedIp::first();

        $this->delete("/hx-control/security/block/{$blocked->id}")->assertRedirect();

        $this->assertDatabaseCount('blocked_ips', 0);
        $this->assertFalse(BlockedIp::blocks('203.0.113.4'));
    }

    public function test_an_admin_cannot_block_their_own_address(): void
    {
        // The test client reports 127.0.0.1; blocking it from here would be an
        // admin locking themselves out of the console.
        $this->post('/hx-control/security/block', [
            'ip' => '127.0.0.1',
            'reason' => 'oops',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('blocked_ips', 0);
    }

    public function test_an_expired_block_stops_blocking(): void
    {
        BlockedIp::create([
            'ip' => '203.0.113.9',
            'expires_at' => now()->subHour(),
        ]);

        $this->assertFalse(BlockedIp::blocks('203.0.113.9'));
    }

    public function test_a_blocked_address_is_refused_at_the_door(): void
    {
        BlockedIp::create(['ip' => '203.0.113.7', 'reason' => 'abuse']);

        Auth::guard('superadmin')->logout();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/login')
            ->assertForbidden();
    }

    public function test_an_unblocked_address_passes(): void
    {
        BlockedIp::create(['ip' => '203.0.113.7']);

        Auth::guard('superadmin')->logout();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])
            ->get('/login')
            ->assertOk();
    }

    public function test_the_security_screen_loads(): void
    {
        $this->get('/hx-control/security')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/System/Security')
                ->has('kpis'),
        );
    }

    // ---- audit -----------------------------------------------------------

    public function test_the_audit_log_lists_recorded_actions(): void
    {
        $tenant = Tenant::factory()->create();

        $this->post("/hx-control/tenants/{$tenant->id}/suspend", ['reason' => 'test']);

        $this->get('/hx-control/audit')->assertInertia(
            function (AssertableInertia $page) use ($tenant) {
                $actions = collect($page->toArray()['props']['entries']['data'])
                    ->pluck('action');

                $this->assertTrue($actions->contains('tenants.suspend'));

                $row = collect($page->toArray()['props']['entries']['data'])
                    ->firstWhere('action', 'tenants.suspend');

                $this->assertSame('superadmin', $row['actorType']);
                $this->assertSame($tenant->id, $row['tenantId']);
            },
        );
    }

    public function test_the_audit_log_can_be_filtered_by_actor(): void
    {
        ActivityLog::create(['actor_type' => 'system', 'action' => 'cron.ran']);
        ActivityLog::create([
            'actor_type' => 'superadmin',
            'actor_id' => $this->owner->id,
            'action' => 'admin.login',
        ]);

        $this->get('/hx-control/audit?actor=system')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'cron.ran'),
        );
    }

    public function test_there_is_no_way_to_edit_or_delete_an_audit_entry(): void
    {
        $entry = ActivityLog::create([
            'actor_type' => 'superadmin',
            'actor_id' => $this->owner->id,
            'action' => 'tenants.suspend',
        ]);

        // Not "returns 403" — no such route exists, so no future change can
        // accidentally expose one.
        $this->delete("/hx-control/audit/{$entry->id}")->assertNotFound();
        $this->patch("/hx-control/audit/{$entry->id}", ['action' => 'nothing'])
            ->assertNotFound();

        $this->assertDatabaseHas('activity_log', [
            'id' => $entry->id,
            'action' => 'tenants.suspend',
        ]);
    }

    public function test_an_admin_grade_can_read_the_audit_log(): void
    {
        $admin = Superadmin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'superadmin');

        $this->get('/hx-control/audit')->assertOk();
    }

    public function test_a_support_grade_cannot_read_the_audit_log(): void
    {
        $support = Superadmin::factory()->support()->create();

        $this->actingAs($support, 'superadmin');

        $this->get('/hx-control/audit')->assertForbidden();
    }

    // ---- backups ---------------------------------------------------------

    public function test_the_backups_screen_loads(): void
    {
        $this->get('/hx-control/backups')->assertInertia(
            fn (AssertableInertia $page) => $page->component('Admin/System/Backups'),
        );
    }

    public function test_there_is_no_restore_route(): void
    {
        // Restoring from a web button is one mis-click from overwriting every
        // reseller's live data. It is a shell command on purpose.
        //
        // 405 rather than 404: the path collides with GET /backups/{file}, so
        // the router answers "no such method" — which is the same thing said
        // differently. What matters is that nothing handles it.
        $this->post('/hx-control/backups/restore', ['file' => 'backup.dump'])
            ->assertMethodNotAllowed();

        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(
                fn ($route) => str_contains($route->uri(), 'restore'),
            ),
        );
    }

    public function test_a_download_cannot_escape_the_backup_directory(): void
    {
        $this->get('/hx-control/backups/'.urlencode('../../.env'))->assertNotFound();
    }

    public function test_a_non_dump_filename_is_refused(): void
    {
        $this->get('/hx-control/backups/database.sqlite')->assertNotFound();
    }

    // ---- access ----------------------------------------------------------

    public function test_only_an_owner_reaches_the_system_screens(): void
    {
        $admin = Superadmin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'superadmin');

        foreach (['settings', 'security', 'backups'] as $path) {
            $this->get("/hx-control/{$path}")->assertForbidden();
        }

        $this->post('/hx-control/settings', $this->settingsPayload())->assertForbidden();
        $this->post('/hx-control/security/block', ['ip' => '203.0.113.1'])
            ->assertForbidden();
    }

    public function test_a_reseller_cannot_reach_the_system_screens(): void
    {
        Auth::guard('superadmin')->logout();

        $this->actingAs(Tenant::factory()->create(), 'tenant');

        foreach (['settings', 'security', 'audit', 'backups'] as $path) {
            $this->get("/hx-control/{$path}")->assertRedirect(route('admin.login'));
        }
    }
}
