<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Panel\ServiceCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Reading a panel's catalogue and turning chosen entries into a reseller's
 * own priced services.
 */
class ImportServicesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->panel = TenantPanel::factory()->for($this->tenant)->create(['status' => 'active']);
    }

    /** A panel's service list, in the shape the SMM API returns. */
    private function fakeCatalogue(array $services): void
    {
        Http::fake(['*' => Http::response($services)]);
    }

    private function sampleService(array $overrides = []): array
    {
        return [
            'service' => '101',
            'name' => 'Instagram Followers | 30 Days Refill',
            'rate' => '2.00',
            'min' => 100,
            'max' => 50000,
            ...$overrides,
        ];
    }

    // ---- reading the catalogue -------------------------------------------

    public function test_it_shows_the_panels_services(): void
    {
        $this->fakeCatalogue([$this->sampleService()]);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'services'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/ImportServices')
                ->has('services', 1)
                ->where('services.0.provider_service_id', '101')
                ->where('services.0.platform', 'Instagram')
                ->etc()
            );
    }

    public function test_it_splits_the_platform_out_of_the_name(): void
    {
        $this->fakeCatalogue([
            $this->sampleService(['name' => 'TikTok Views | Fast']),
        ]);

        $catalogue = app(ServiceCatalogue::class)->forPanel($this->panel);

        $this->assertSame('TikTok', $catalogue->services[0]['platform']);
        $this->assertSame('Views', $catalogue->services[0]['category']);
    }

    public function test_an_unrecognised_platform_falls_back_to_other(): void
    {
        // Guessing wrongly is worse than saying "Other" — the reseller can
        // correct it, but a wrong platform hides the service from customers.
        $this->fakeCatalogue([
            $this->sampleService(['name' => 'Website Traffic Worldwide']),
        ]);

        $catalogue = app(ServiceCatalogue::class)->forPanel($this->panel);

        $this->assertSame('Other', $catalogue->services[0]['platform']);
    }

    public function test_it_suggests_a_price_above_cost(): void
    {
        $this->fakeCatalogue([$this->sampleService(['rate' => '2.00'])]);

        $catalogue = app(ServiceCatalogue::class)->forPanel($this->panel);

        // Cost 2.00 plus 30%.
        $this->assertSame('2.6000', $catalogue->services[0]['suggested_price']);
    }

    public function test_it_marks_services_already_imported(): void
    {
        BotService::factory()->for($this->tenant)->create([
            'panel_id' => $this->panel->id,
            'provider_service_id' => '101',
        ]);

        $this->fakeCatalogue([$this->sampleService(['service' => '101'])]);

        $catalogue = app(ServiceCatalogue::class)->forPanel($this->panel);

        $this->assertTrue($catalogue->services[0]['imported']);
    }

    public function test_entries_missing_an_id_or_name_are_skipped(): void
    {
        $this->fakeCatalogue([
            $this->sampleService(),
            ['service' => '', 'name' => 'No id'],
            ['service' => '999', 'name' => ''],
        ]);

        $catalogue = app(ServiceCatalogue::class)->forPanel($this->panel);

        $this->assertCount(1, $catalogue->services);
    }

    public function test_a_panel_that_will_not_answer_is_reported_on_the_page(): void
    {
        $this->fakeCatalogue(['error' => 'Invalid API key']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'services'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('catalogueError', 'Invalid API key')
                ->has('services', 0)
                ->etc()
            );
    }

    public function test_without_a_panel_it_sends_you_back(): void
    {
        $this->panel->delete();

        // To the panel step itself: the wizard's front door would send them
        // straight back here whenever the panel step had been skipped.
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding.step', 'services'))
            ->assertRedirect(route('onboarding.step', 'panel'));
    }

    // ---- importing --------------------------------------------------------

    private function importPayload(array $overrides = []): array
    {
        return [
            'panel_id' => $this->panel->id,
            'services' => [[
                'provider_service_id' => '101',
                'name' => 'Instagram Followers',
                'platform' => 'Instagram',
                'category' => 'Followers',
                'cost_price' => '2.00',
                'my_price' => '2.60',
                'min_quantity' => 100,
                'max_quantity' => 50000,
                ...$overrides,
            ]],
        ];
    }

    public function test_it_imports_a_chosen_service_at_the_resellers_price(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.services.store'), $this->importPayload())
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('bot_services', [
            'tenant_id' => $this->tenant->id,
            'panel_id' => $this->panel->id,
            'provider_service_id' => '101',
            'platform' => 'Instagram',
            'my_price' => '2.6000',
            'cost_price' => '2.0000',
            'status' => 'active',
        ]);
    }

    public function test_importing_from_the_services_page_stays_on_the_services_page(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('onboarding.services.store'), $this->importPayload())
            ->assertRedirect(route('services.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('bot_services', ['tenant_id' => $this->tenant->id, 'provider_service_id' => '101']);
    }

    public function test_importing_from_settings_stays_in_settings(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->from(route('settings'))
            ->post(route('onboarding.services.store'), $this->importPayload())
            ->assertRedirect(route('settings'));
    }

    public function test_importing_from_the_wizard_still_moves_on(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->from(route('onboarding.step', 'services'))
            ->post(route('onboarding.services.store'), $this->importPayload())
            ->assertRedirect(route('onboarding'));
    }

    public function test_importing_twice_updates_rather_than_duplicates(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.services.store'), $this->importPayload());

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.services.store'), $this->importPayload(['my_price' => '3.50']));

        $this->assertDatabaseCount('bot_services', 1);
        $this->assertDatabaseHas('bot_services', ['my_price' => '3.5000']);
    }

    public function test_a_free_price_is_refused(): void
    {
        // Selling at zero would have the bot give stock away.
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.services.store'), $this->importPayload(['my_price' => '0']))
            ->assertSessionHasErrors('services.0.my_price');

        $this->assertDatabaseCount('bot_services', 0);
    }

    public function test_importing_nothing_is_refused(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.services.store'), [
                'panel_id' => $this->panel->id,
                'services' => [],
            ])
            ->assertSessionHasErrors('services');
    }

    public function test_a_backwards_quantity_range_is_corrected(): void
    {
        $this->actingAs($this->tenant, 'tenant')->post(
            route('onboarding.services.store'),
            $this->importPayload(['min_quantity' => 500, 'max_quantity' => 100]),
        );

        // Max is raised to min rather than storing a range nothing can satisfy.
        $this->assertDatabaseHas('bot_services', [
            'min_quantity' => 500,
            'max_quantity' => 500,
        ]);
    }

    public function test_it_cannot_import_into_another_tenants_panel(): void
    {
        $other = Tenant::factory()->create();
        $othersPanel = TenantPanel::factory()->for($other)->create();

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.services.store'), [
                ...$this->importPayload(),
                'panel_id' => $othersPanel->id,
            ])
            ->assertSessionHasErrors('panel_id');

        $this->assertDatabaseCount('bot_services', 0);
    }

    public function test_importing_completes_that_wizard_step(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->post(route('onboarding.services.store'), $this->importPayload());

        // With a panel and services done, the next stop is WhatsApp.
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('onboarding'))
            ->assertRedirect(route('onboarding.step', 'whatsapp'));
    }
}
