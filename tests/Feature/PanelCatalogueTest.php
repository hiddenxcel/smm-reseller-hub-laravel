<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Load services" in the import dialog: read what a panel sells so the
 * reseller can pick from it.
 *
 * This endpoint used to answer 500 every time — it read a property its result
 * does not have — and the dialog took the empty reply for an empty panel, so
 * importing never showed a single service and never said why.
 */
class PanelCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
        $this->panel = TenantPanel::factory()->for($this->tenant)->create();
    }

    /** What a panel's `services` action returns. */
    private function panelSells(int $count): void
    {
        $services = [];

        for ($i = 1; $i <= $count; $i++) {
            $services[] = [
                'service' => $i,
                'name' => $i % 2 === 0 ? "Instagram Followers #{$i}" : "TikTok Views #{$i}",
                'type' => 'default',
                'category' => 'Test',
                'rate' => '1.5000',
                'min' => '10',
                'max' => '50000',
            ];
        }

        Http::fake(['*' => Http::response($services)]);
    }

    private function load(?TenantPanel $panel = null)
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->getJson(route('services.panel-catalogue', $panel ?? $this->panel));
    }

    public function test_loading_a_panel_returns_its_services(): void
    {
        $this->panelSells(4);

        $response = $this->load()->assertOk();

        $response->assertJsonPath('failed', false);
        $response->assertJsonCount(4, 'services');
        $response->assertJsonPath('total', 4);
        $response->assertJsonPath('services.0.provider_service_id', '1');
        $response->assertJsonPath('services.1.platform', 'Instagram');
    }

    public function test_a_panel_that_cannot_be_read_says_so_instead_of_looking_empty(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $response = $this->load()->assertOk();

        $response->assertJsonPath('failed', true);
        $this->assertNotEmpty($response->json('message'));
        $response->assertJsonCount(0, 'services');
    }

    public function test_a_panel_that_rejects_the_key_reports_its_own_message(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid API key'])]);

        $this->load()
            ->assertJsonPath('failed', true)
            ->assertJsonPath('message', 'Invalid API key');
    }

    public function test_a_panel_with_hundreds_of_services_is_not_cut_short(): void
    {
        // The old cap of 300 hid everything after it from the picker and from search.
        $this->panelSells(465);

        $response = $this->load()->assertOk();

        $response->assertJsonCount(465, 'services');
        $response->assertJsonPath('total', 465);
    }

    public function test_services_already_imported_are_marked(): void
    {
        $this->panelSells(3);
        BotService::factory()->for($this->tenant)->create([
            'panel_id' => $this->panel->id,
            'provider_service_id' => '2',
        ]);

        $services = collect($this->load()->json('services'))->keyBy('provider_service_id');

        $this->assertTrue($services['2']['imported']);
        $this->assertFalse($services['1']['imported']);
    }

    public function test_another_resellers_panel_cannot_be_read(): void
    {
        $theirs = TenantPanel::factory()->for(Tenant::factory()->create())->create();
        Http::fake();

        $this->load($theirs)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_it_needs_a_login(): void
    {
        $this->getJson(route('services.panel-catalogue', $this->panel))->assertUnauthorized();
    }
}
