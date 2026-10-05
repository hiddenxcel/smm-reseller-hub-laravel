<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\BotCustomer;
use App\Models\BotService;
use App\Models\Superadmin;
use App\Models\Tenant;
use App\Models\TenantPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo account's password is published, so the person signed in is not the
 * person who owns the data — and there may be several of them at once.
 *
 * Two mechanisms are under test, and the order matters. The read-only lock is
 * what prevents harm; the scheduled reset only clears up afterwards. A ten
 * minute window is long enough to change the password and lock everyone else
 * out, so a test suite that only proved the reset works would be testing the
 * wrong half.
 */
class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_EMAIL = 'demo@wizard.test';

    private Tenant $demo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.email' => self::DEMO_EMAIL]);

        $this->demo = Tenant::factory()->create(['email' => self::DEMO_EMAIL]);
    }

    // ---- the lock --------------------------------------------------------

    public function test_the_demo_account_can_still_read_every_screen(): void
    {
        $this->actingAs($this->demo, 'tenant')
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($this->demo, 'tenant')
            ->get(route('orders.index'))
            ->assertOk();

        $this->actingAs($this->demo, 'tenant')
            ->get(route('settings', 'panel'))
            ->assertOk();
    }

    public function test_a_write_from_the_demo_account_is_refused(): void
    {
        $this->actingAs($this->demo, 'tenant')
            ->patch(route('profile.update'), [
                'business_name' => 'Renamed By A Stranger',
                'email' => 'attacker@example.com',
            ])
            ->assertSessionHas('error');

        $this->assertSame(self::DEMO_EMAIL, $this->demo->fresh()->email);
    }

    /**
     * The one that matters most: a changed password locks every other visitor
     * out of a shared account, and no later reset helps them in the meantime.
     */
    public function test_the_demo_password_cannot_be_changed(): void
    {
        $before = $this->demo->fresh()->password_hash;

        $this->actingAs($this->demo, 'tenant')
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'taken-over-1234',
                'password_confirmation' => 'taken-over-1234',
            ])
            ->assertSessionHas('error');

        $this->assertSame($before, $this->demo->fresh()->password_hash);
    }

    /** Panel credentials are the reseller's own account with a third party. */
    public function test_the_demo_cannot_connect_a_panel(): void
    {
        $this->actingAs($this->demo, 'tenant')
            ->post(route('onboarding.panel.store'), [
                'api_url' => 'https://attacker.example.com/api/v2',
                'api_key' => 'stolen',
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('tenant_panels', 0);
    }

    public function test_the_demo_cannot_delete_its_own_account(): void
    {
        $this->actingAs($this->demo, 'tenant')
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tenants', ['email' => self::DEMO_EMAIL]);
    }

    /**
     * Signing out has to keep working. It is a POST, but refusing it would
     * strand a visitor in an account they cannot leave — and hand the session
     * to whoever uses that browser next.
     */
    public function test_the_demo_can_still_sign_out(): void
    {
        $this->actingAs($this->demo, 'tenant')
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest('tenant');
    }

    public function test_an_ordinary_account_is_not_locked(): void
    {
        $other = Tenant::factory()->create(['email' => 'real@example.com']);

        $this->actingAs($other, 'tenant')
            ->patch(route('profile.update'), [
                'business_name' => 'My New Name',
                'email' => 'real@example.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('My New Name', $other->fresh()->business_name);
    }

    /** An install with no demo configured must lock nobody. */
    public function test_nothing_is_locked_when_no_demo_is_configured(): void
    {
        config(['demo.email' => '']);

        $this->actingAs($this->demo, 'tenant')
            ->patch(route('profile.update'), [
                'business_name' => 'Changed',
                'email' => self::DEMO_EMAIL,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Changed', $this->demo->fresh()->business_name);
    }

    /** The address is typed by different people; a capital must not unlock it. */
    public function test_the_match_ignores_case(): void
    {
        config(['demo.email' => 'DEMO@WIZARD.TEST']);

        $this->actingAs($this->demo, 'tenant')
            ->patch(route('profile.update'), [
                'business_name' => 'Renamed',
                'email' => self::DEMO_EMAIL,
            ])
            ->assertSessionHas('error');
    }

    /**
     * The lock runs on the whole web group, which includes the admin console —
     * where $request->user() is a Superadmin, not a Tenant. A signature that
     * only accepted tenants turned every admin write into a 500.
     */
    public function test_an_admin_writing_in_the_console_is_unaffected(): void
    {
        $admin = Superadmin::factory()->owner()->create();

        $this->actingAs($admin, 'superadmin')
            ->post(route('admin.settings.save'), [
                'company_name' => 'Auto Resellers Hub',
                'support_email' => 'help@example.com',
            ])
            ->assertRedirect();
    }

    // ---- the API, which the web middleware never sees ---------------------

    /** ApiKey::issue() is the only thing that hands back the plaintext. */
    private function apiKey(Tenant $tenant): string
    {
        $customer = BotCustomer::factory()->for($tenant)->create(['balance' => '500.00']);

        [, $plaintext] = ApiKey::issue($customer);

        return $plaintext;
    }

    public function test_the_demo_api_key_cannot_place_an_order(): void
    {
        $service = BotService::factory()->for($this->demo)->create();

        $this->postJson(route('api.v2'), [
            'key' => $this->apiKey($this->demo),
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://instagram.com/x',
            'quantity' => 1000,
        ])->assertOk()->assertJsonStructure(['error']);

        $this->assertDatabaseCount('bot_orders', 0);
    }

    /** Case is normalised the same way the controller resolves the action. */
    public function test_the_demo_api_block_is_not_dodged_by_capitals(): void
    {
        $service = BotService::factory()->for($this->demo)->create();

        $this->postJson(route('api.v2'), [
            'key' => $this->apiKey($this->demo),
            'action' => 'ADD',
            'service' => $service->id,
            'link' => 'https://instagram.com/x',
            'quantity' => 1000,
        ])->assertOk()->assertJsonStructure(['error']);

        $this->assertDatabaseCount('bot_orders', 0);
    }

    /** Reading is the point of an API demo, so it stays open. */
    public function test_the_demo_api_key_can_still_read(): void
    {
        BotService::factory()->for($this->demo)->create();

        $this->postJson(route('api.v2'), [
            'key' => $this->apiKey($this->demo),
            'action' => 'balance',
        ])->assertOk()->assertJsonMissing(['error' => config('demo.message')]);
    }

    // ---- the reset -------------------------------------------------------

    public function test_the_reset_rebuilds_the_account_and_clears_stray_data(): void
    {
        // Something a visitor left behind, or that predates the lock.
        TenantPanel::factory()->for($this->demo)->create(['name' => 'Left Behind']);

        $this->artisan('demo:reset --force')->assertSuccessful();

        $this->assertDatabaseMissing('tenant_panels', ['name' => 'Left Behind']);

        // Rebuilt, not merely emptied — the published login must still work.
        $this->assertDatabaseHas('tenants', ['email' => self::DEMO_EMAIL]);
        $this->assertDatabaseHas('tenant_panels', ['name' => 'Main Panel']);
    }

    /**
     * The guard that matters: this command deletes a tenant and everything
     * cascading off it. Pointed at nothing, it must touch nothing.
     */
    public function test_the_reset_does_nothing_when_no_demo_is_configured(): void
    {
        config(['demo.email' => '']);

        $other = Tenant::factory()->create(['email' => 'real@example.com']);

        $this->artisan('demo:reset --force')
            ->expectsOutputToContain('No demo account is configured')
            ->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['email' => 'real@example.com']);
        $this->assertDatabaseHas('tenants', ['id' => $other->id]);
    }

    /** Only the demo is rebuilt; every other account is left alone. */
    public function test_the_reset_leaves_other_resellers_untouched(): void
    {
        $other = Tenant::factory()->create(['email' => 'real@example.com']);
        $panel = TenantPanel::factory()->for($other)->create(['name' => 'Their Panel']);

        $this->artisan('demo:reset --force')->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['id' => $other->id]);
        $this->assertDatabaseHas('tenant_panels', ['id' => $panel->id, 'name' => 'Their Panel']);
    }

    /** A fresh database has no demo row yet; seeding one is the right answer. */
    public function test_the_reset_seeds_the_account_when_it_is_missing(): void
    {
        $this->demo->delete();

        $this->artisan('demo:reset --force')->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['email' => self::DEMO_EMAIL]);
    }
}
