<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotSettings;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
use App\Services\Payments\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The wizard exists because an empty dashboard is where new resellers give
 * up. Its job is to always know the next thing to do.
 */
class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function completePanelStep(): TenantPanel
    {
        return TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);
    }

    private function completeServicesStep(): void
    {
        BotService::factory()->for($this->tenant)->create(['status' => 'active']);
    }

    private function completeWhatsAppStep(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create();
    }

    // ---- where a finished step sends you ---------------------------------

    /**
     * The same forms serve the wizard and Settings, so where they return to
     * depends on where they were submitted from. Both directions are asserted
     * with a Referer, because posting without one takes a fallback path and
     * hides exactly the mistake this is here to catch: a reseller who saves a
     * panel inside the wizard and lands back on the panel form.
     */
    public function test_saving_from_the_wizard_advances_rather_than_returning_to_the_form(): void
    {
        BotService::factory()->for($this->tenant)->create(['status' => 'active']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('onboarding.step', 'whatsapp'))
            ->post(route('onboarding.whatsapp.store'), [
                'phone_number_id' => 'wizard-1',
                'token' => 'a-token',
                'display_number' => '+255700000009',
                'bot_type' => 'order',
            ])
            ->assertRedirect(route('onboarding'));
    }

    public function test_saving_from_settings_stays_in_settings(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->from(route('settings', 'whatsapp'))
            ->post(route('onboarding.whatsapp.store'), [
                'phone_number_id' => 'settings-1',
                'token' => 'a-token',
                'display_number' => '+255700000008',
                'bot_type' => 'order',
            ])
            ->assertRedirect(route('settings', 'whatsapp'));
    }

    // ---- where the wizard sends you --------------------------------------

    public function test_registering_lands_in_the_wizard_not_the_dashboard(): void
    {
        $this->post(route('register'), [
            'business_name' => 'New Shop',
            'email' => 'new@example.com',
            'password' => 'password-that-is-long',
            'password_confirmation' => 'password-that-is-long',
        ])->assertRedirect(route('onboarding', absolute: false));
    }

    public function test_a_fresh_tenant_starts_at_the_panel_step(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding'))
            ->assertRedirect(route('onboarding.step', OnboardingStep::ConnectPanel->value));
    }

    public function test_it_skips_to_the_first_unfinished_step(): void
    {
        $this->completePanelStep();
        $this->completeServicesStep();

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding'))
            ->assertRedirect(route('onboarding.step', OnboardingStep::ConnectWhatsApp->value));
    }

    public function test_a_finished_setup_goes_to_the_dashboard(): void
    {
        $this->completePanelStep();
        $this->completeServicesStep();
        $this->completeWhatsAppStep();
        TenantPaymentGateway::factory()->for($this->tenant)->create([
            'gateway' => 'snippe',
            'status' => 'active',
        ]);
        BotSettings::save($this->tenant->id, 'order', ['shop' => ['bot_tested' => true]]);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_an_unknown_step_returns_to_the_wizard(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'not-a-step'))
            ->assertRedirect(route('onboarding'));
    }

    public function test_the_wizard_needs_a_login(): void
    {
        $this->get(route('onboarding'))->assertRedirect(route('login'));
    }

    public function test_it_reports_progress_to_the_page(): void
    {
        $this->completePanelStep();

        $response = $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', OnboardingStep::ImportServices->value));

        $response->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('completed', 1)
            ->where('readyToGoLive', false)
            ->has('steps', 5)
        );
    }

    // ---- connecting a panel ----------------------------------------------

    public function test_it_connects_a_panel_and_detects_its_settings(): void
    {
        // Body-param auth answers, so that is what should be stored.
        Http::fake(['*' => Http::response(['balance' => '250.00', 'currency' => 'USD'])]);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.panel.store'), [
                'api_url' => 'mypanel.com',
                'api_key' => 'secret-key',
            ])
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('tenant_panels', [
            'tenant_id' => $this->tenant->id,
            // Never asked for — taken from the address.
            'name' => 'Mypanel',
            'api_url' => 'https://mypanel.com/api/v2',
            'auth_method' => 'param',
            'status' => 'active',
        ]);
    }

    public function test_it_names_a_panel_from_its_address(): void
    {
        Http::fake(['*' => Http::response(['balance' => '10.00'])]);

        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.panel.store'), [
            'api_url' => 'https://www.best-smm.co.uk/api/v2',
            'api_key' => 'key',
        ]);

        // `www.` and the two-part suffix carry no meaning; the hyphen becomes
        // a space so the label reads as words.
        $this->assertDatabaseHas('tenant_panels', ['name' => 'Best Smm']);
    }

    public function test_reconnecting_a_panel_keeps_the_name_the_reseller_chose(): void
    {
        Http::fake(['*' => Http::response(['balance' => '10.00'])]);

        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.panel.store'), [
            'api_url' => 'mypanel.com',
            'api_key' => 'first-key',
        ]);

        TenantPanel::where('tenant_id', $this->tenant->id)->update(['name' => 'Renamed by hand']);

        // Rotating the key must not undo the rename.
        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.panel.store'), [
            'api_url' => 'mypanel.com',
            'api_key' => 'second-key',
        ]);

        $this->assertDatabaseCount('tenant_panels', 1);
        $this->assertDatabaseHas('tenant_panels', ['name' => 'Renamed by hand']);
    }

    public function test_it_appends_the_api_path_to_a_bare_domain(): void
    {
        Http::fake(['*' => Http::response(['balance' => '10.00'])]);

        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.panel.store'), [
            'api_url' => 'https://panel.example.com',
            'api_key' => 'key',
        ]);

        $this->assertDatabaseHas('tenant_panels', [
            'api_url' => 'https://panel.example.com/api/v2',
        ]);
    }

    public function test_it_leaves_a_url_that_already_points_at_the_api(): void
    {
        Http::fake(['*' => Http::response(['balance' => '10.00'])]);

        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.panel.store'), [
            'api_url' => 'https://panel.example.com/api/v2',
            'api_key' => 'key',
        ]);

        $this->assertDatabaseHas('tenant_panels', [
            'api_url' => 'https://panel.example.com/api/v2',
        ]);
    }

    public function test_a_panel_that_will_not_answer_is_reported_not_stored(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid API key'])]);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.panel.store'), [
                'api_url' => 'panel.example.com',
                'api_key' => 'wrong',
            ])
            ->assertSessionHasErrors('api_url');

        $this->assertDatabaseCount('tenant_panels', 0);
    }

    public function test_connecting_a_panel_completes_that_step(): void
    {
        Http::fake(['*' => Http::response(['balance' => '10.00'])]);

        $progress = OnboardingProgress::for($this->tenant);
        $this->assertFalse($progress->isComplete(OnboardingStep::ConnectPanel));

        $this->actingAs($this->tenant, 'tenant')->post(route('onboarding.panel.store'), [
            'api_url' => 'panel.example.com',
            'api_key' => 'key',
        ]);

        $this->assertTrue(
            OnboardingProgress::for($this->tenant->fresh())->isComplete(OnboardingStep::ConnectPanel)
        );
    }

    // ---- progress is derived, not stored ---------------------------------

    public function test_progress_follows_the_data(): void
    {
        // Deleting the only panel should send the reseller back to step one,
        // which a stored flag would get wrong.
        $panel = $this->completePanelStep();
        $this->assertTrue(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::ConnectPanel)
        );

        $panel->delete();

        $this->assertFalse(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::ConnectPanel)
        );
    }

    public function test_payments_are_optional_for_going_live(): void
    {
        $this->completePanelStep();
        $this->completeServicesStep();
        $this->completeWhatsAppStep();
        BotSettings::save($this->tenant->id, 'order', ['shop' => ['bot_tested' => true]]);

        // No gateway configured, but the required steps are done.
        $this->assertTrue(OnboardingProgress::for($this->tenant)->isReadyToGoLive());
    }

    /**
     * Connecting a gateway with no client behind it is not setup done — the
     * reseller would finish the wizard unable to take a payment.
     *
     * The example is read out of config rather than named here: it used to say
     * 'stripe', which stopped meaning anything the day Stripe got a client.
     */
    public function test_a_gateway_that_is_not_wired_up_does_not_count(): void
    {
        $unwired = collect(array_keys(Gateway::all()))
            ->first(fn (string $code) => ! Gateway::isReady($code));

        if ($unwired === null) {
            $this->markTestSkipped('Every configured gateway is wired up.');
        }

        TenantPaymentGateway::factory()->for($this->tenant)->create([
            'gateway' => $unwired,
            'status' => 'active',
        ]);

        $this->assertFalse(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::SetupPayments)
        );
    }

    public function test_another_tenants_setup_does_not_count_as_yours(): void
    {
        $other = Tenant::factory()->create();
        TenantPanel::factory()->for($other)->create(['status' => 'active']);

        $this->assertFalse(
            OnboardingProgress::for($this->tenant)->isComplete(OnboardingStep::ConnectPanel)
        );
    }
}
