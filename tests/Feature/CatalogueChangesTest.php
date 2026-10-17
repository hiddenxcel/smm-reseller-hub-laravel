<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\CatalogueChange;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Catalogue\PanelSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogueChangesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->panel = TenantPanel::factory()->for($this->tenant)->create();
    }

    private function service(array $attributes = []): BotService
    {
        return BotService::factory()->for($this->tenant)->create($attributes + [
            'panel_id' => $this->panel->id,
            'provider_service_id' => '900',
            'cost_price' => '2.0000',
            'my_price' => '2.5000',
            'status' => BotService::ACTIVE,
        ]);
    }

    private function panelSays(array $services): void
    {
        // A fresh factory each time: stubs registered first would otherwise win.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response($services)]);
    }

    private function row(string $rate): array
    {
        return ['service' => '900', 'name' => 'X', 'rate' => $rate, 'min' => 100, 'max' => 5000];
    }

    public function test_with_auto_sync_off_a_cost_rise_waits_for_approval(): void
    {
        $service = $this->service();
        $this->panelSays([$this->row('3.0000')]);

        $result = PanelSync::for($this->tenant)->run($this->panel);

        $fresh = $service->fresh();
        $this->assertSame('2.0000', $fresh->cost_price);
        $this->assertSame('2.5000', $fresh->my_price);
        $this->assertSame(1, $result['queued']);

        $change = CatalogueChange::withoutTenantScope()->first();
        $this->assertSame(CatalogueChange::PENDING, $change->status);
        $this->assertSame('3.0000', $change->new_cost);
        // Margin kept: 2.5 / 2.0 * 3.0
        $this->assertSame('3.7500', $change->proposed_price);
    }

    public function test_syncing_twice_does_not_stack_duplicates(): void
    {
        $this->service();
        $this->panelSays([$this->row('3.0000')]);

        PanelSync::for($this->tenant)->run($this->panel);
        PanelSync::for($this->tenant)->run($this->panel);

        $this->assertSame(1, CatalogueChange::withoutTenantScope()->count());
    }

    public function test_allowing_a_cost_change_applies_cost_and_price(): void
    {
        $service = $this->service();
        $this->panelSays([$this->row('3.0000')]);
        PanelSync::for($this->tenant)->run($this->panel);
        $change = CatalogueChange::withoutTenantScope()->first();

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.changes'))
            ->post(route('services.changes.approve', $change))
            ->assertSessionHas('success');

        $fresh = $service->fresh();
        $this->assertSame('3.0000', $fresh->cost_price);
        $this->assertSame('3.7500', $fresh->my_price);
        $this->assertSame(CatalogueChange::APPLIED, $change->fresh()->status);
    }

    public function test_a_dismissed_change_stays_dismissed_until_the_cost_moves_again(): void
    {
        $service = $this->service();
        $this->panelSays([$this->row('3.0000')]);
        PanelSync::for($this->tenant)->run($this->panel);
        $change = CatalogueChange::withoutTenantScope()->first();

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('services.changes.dismiss', $change));

        PanelSync::for($this->tenant)->run($this->panel);

        $this->assertSame(0, CatalogueChange::withoutTenantScope()->where('status', CatalogueChange::PENDING)->count());
        $this->assertSame('2.0000', $service->fresh()->cost_price);

        $this->panelSays([$this->row('3.5000')]);
        PanelSync::for($this->tenant)->run($this->panel);

        $this->assertSame(1, CatalogueChange::withoutTenantScope()->where('status', CatalogueChange::PENDING)->count());
    }

    public function test_a_removed_service_is_paused_and_waits_then_leaves_when_allowed(): void
    {
        $service = $this->service();
        $this->panelSays([]);

        PanelSync::for($this->tenant)->run($this->panel);

        $this->assertSame(BotService::PAUSED, $service->fresh()->status);
        $change = CatalogueChange::withoutTenantScope()->first();
        $this->assertSame(CatalogueChange::REMOVED, $change->kind);
        $this->assertSame(CatalogueChange::PENDING, $change->status);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('services.changes.approve', $change));

        $this->assertNull(BotService::withoutTenantScope()->find($service->id));
        $this->assertSame(CatalogueChange::APPLIED, $change->fresh()->status);
    }

    public function test_a_service_that_comes_back_clears_its_waiting_removal(): void
    {
        $this->service();
        $this->panelSays([]);
        PanelSync::for($this->tenant)->run($this->panel);

        $this->panelSays([$this->row('2.0000')]);
        PanelSync::for($this->tenant)->run($this->panel);

        $this->assertSame(0, CatalogueChange::withoutTenantScope()->count());
    }

    public function test_with_auto_sync_on_changes_apply_and_leave_a_record(): void
    {
        $this->tenant->forceFill(['auto_catalogue_sync' => true])->save();
        $service = $this->service();
        $this->panelSays([$this->row('3.0000')]);

        PanelSync::for($this->tenant->fresh())->run($this->panel);

        $fresh = $service->fresh();
        $this->assertSame('3.0000', $fresh->cost_price);
        $this->assertSame('3.7500', $fresh->my_price);
        $this->assertSame(
            CatalogueChange::APPLIED,
            CatalogueChange::withoutTenantScope()->first()->status,
        );
    }

    public function test_turning_auto_sync_on_answers_everything_waiting(): void
    {
        $service = $this->service();
        $this->panelSays([$this->row('3.0000')]);
        PanelSync::for($this->tenant)->run($this->panel);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('services.changes.auto'), ['enabled' => true])
            ->assertSessionHas('success');

        $this->assertTrue($this->tenant->fresh()->auto_catalogue_sync);
        $this->assertSame('3.7500', $service->fresh()->my_price);
    }

    public function test_the_page_lists_what_is_waiting(): void
    {
        $this->service(['name' => 'Instagram Followers']);
        $this->panelSays([$this->row('3.0000')]);
        PanelSync::for($this->tenant)->run($this->panel);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('services.changes'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Services/Changes')
                ->where('autoSync', false)
                ->has('pending', 1));
    }

    public function test_another_resellers_change_cannot_be_answered(): void
    {
        $other = Tenant::factory()->create();
        $change = CatalogueChange::withoutTenantScope()->create([
            'tenant_id' => $other->id,
            'service_name' => 'Theirs',
            'kind' => CatalogueChange::COST,
            'status' => CatalogueChange::PENDING,
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('services.changes.approve', $change))
            ->assertNotFound();
    }
}
