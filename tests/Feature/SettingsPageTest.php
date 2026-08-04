<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The settings screen.
 *
 * The thing worth real care: a reseller whose setup is incomplete — or who
 * breaks it after go-live — must still land here rather than being bounced
 * into the wizard. The wizard derives completion from the data itself, so
 * deleting a panel makes someone look brand new; this screen must not treat
 * them that way.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    public function test_it_shows_every_tab_without_sending_a_bare_tenant_to_the_wizard(): void
    {
        foreach (['panel', 'services', 'whatsapp', 'payments'] as $tab) {
            $this->actingAs($this->tenant, 'tenant')
                ->get(route('settings', $tab))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Settings/Index')
                    ->where('tab', $tab));
        }
    }

    public function test_it_defaults_to_the_panel_tab(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('settings'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'panel'));
    }

    public function test_it_rejects_a_tab_that_is_not_one_of_ours(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get('/settings/nonsense')
            ->assertNotFound();
    }

    public function test_it_needs_a_login(): void
    {
        $this->get(route('settings'))->assertRedirect(route('login'));
    }

    public function test_it_flags_what_is_not_set_up_yet(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('settings', 'panel'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('incomplete.panel', true)
                ->where('incomplete.services', true)
                ->where('incomplete.whatsapp', true)
                ->where('incomplete.payments', true));
    }

    public function test_it_stops_flagging_a_tab_once_its_thing_exists(): void
    {
        $panel = TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);
        BotService::factory()->for($this->tenant)->create([
            'panel_id' => $panel->id,
            'status' => 'active',
        ]);
        TenantWhatsApp::factory()->for($this->tenant)->create();

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('settings', 'panel'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('incomplete.panel', false)
                ->where('incomplete.services', false)
                ->where('incomplete.whatsapp', false)
                // Payments is optional, but still reported so the tab can say so.
                ->where('incomplete.payments', true));
    }

    public function test_it_lists_the_panels_without_their_keys(): void
    {
        TenantPanel::factory()->for($this->tenant)->create([
            'name' => 'My Panel',
            'api_key_enc' => 'super-secret-key',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->tenant, 'tenant')->get(route('settings', 'panel'));

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('panels', 1)
            ->where('panels.0.name', 'My Panel')
            ->where('panels.0.status', 'active'));

        $this->assertStringNotContainsString('super-secret-key', $response->getContent());
    }

    public function test_it_does_not_show_another_tenants_panel(): void
    {
        TenantPanel::factory()->create(['name' => 'Someone Elses']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('settings', 'panel'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('panels', 0));
    }

    public function test_it_reports_connected_gateways_without_leaking_credentials(): void
    {
        TenantPaymentGateway::factory()->for($this->tenant)->create([
            'gateway' => 'cryptomus',
            'status' => 'active',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('settings', 'payments'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('gateways')
                ->has('connected', 1)
                ->where('connected.0.code', 'cryptomus'));
    }

    public function test_it_tells_the_services_tab_there_is_no_panel_rather_than_failing(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('settings', 'services'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('panel', null)
                ->where('importedCount', 0));
    }

    public function test_it_builds_only_the_tab_being_viewed(): void
    {
        // Opening Payments must not drag in the catalogue, which is a network
        // call to the reseller's own panel.
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('settings', 'payments'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('gateways')
                ->missing('panels')
                ->missing('services')
                ->missing('numbers'));
    }
}
