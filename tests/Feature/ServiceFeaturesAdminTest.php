<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Catalogue\ServiceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reseller states what customers are told about a service — quality, speed,
 * drop, refill — when adding it, editing it, or importing it.
 */
class ServiceFeaturesAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function add(array $extra = [])
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.store'), [
                'name' => 'Hand-added',
                'platform' => 'Instagram',
                'provider_service_id' => '4242',
                'my_price' => '2.5',
                'min_quantity' => 100,
                'max_quantity' => 10000,
                ...$extra,
            ]);
    }

    private function saved(): BotService
    {
        return BotService::withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();
    }

    // ---- adding ----------------------------------------------------------

    public function test_a_service_is_added_with_what_customers_are_told(): void
    {
        $this->add([
            'description' => 'Real-looking accounts.',
            'quality' => 'High quality',
            'speed' => '1–6 hours',
            'drop_info' => 'Low drop, 5% at most',
            'refill_info' => 'Free for 365 days',
        ])->assertSessionHasNoErrors();

        $service = $this->saved();
        $this->assertSame('High quality', $service->quality);
        $this->assertSame('1–6 hours', $service->speed);
        $this->assertSame('Low drop, 5% at most', $service->drop_info);
        $this->assertSame('Free for 365 days', $service->refill_info);
    }

    public function test_all_of_it_is_optional(): void
    {
        $this->add()->assertSessionHasNoErrors();

        $service = $this->saved();
        $this->assertNull($service->quality);
        $this->assertNull($service->speed);
        $this->assertNull($service->drop_info);
        $this->assertNull($service->refill_info);
    }

    public function test_each_line_is_held_to_what_fits_on_a_card(): void
    {
        foreach (['quality', 'speed', 'drop_info', 'refill_info'] as $field) {
            $this->add([$field => str_repeat('a', 81)])->assertSessionHasErrors($field);
        }

        $this->add(['drop_info' => str_repeat('a', 80)])->assertSessionHasNoErrors();
    }

    // ---- the unit follows the category -----------------------------------

    public function test_the_unit_is_taken_from_the_category_not_asked_for(): void
    {
        $this->add(['category' => 'Likes'])->assertSessionHasNoErrors();

        $this->assertSame('Likes', $this->saved()->unit_label);
    }

    public function test_a_service_with_no_category_is_counted_in_followers(): void
    {
        $this->add()->assertSessionHasNoErrors();

        $this->assertSame('Followers', $this->saved()->unit_label);
    }

    public function test_changing_the_category_changes_the_unit(): void
    {
        $service = BotService::factory()->for($this->tenant)->create(['category' => 'Followers', 'unit_label' => 'Followers']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->patch(route('services.update', $service), [
                'name' => $service->name,
                'platform' => $service->platform,
                'category' => 'Views',
                'my_price' => (string) $service->my_price,
                'min_quantity' => $service->min_quantity,
                'max_quantity' => $service->max_quantity,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Views', $service->fresh()->unit_label);
    }
    public function test_the_description_is_held_to_what_a_card_can_carry(): void
    {
        $this->add(['description' => str_repeat('a', 501)])->assertSessionHasErrors('description');
        $this->add(['description' => str_repeat('a', 500)])->assertSessionHasNoErrors();
    }

    // ---- editing ---------------------------------------------------------

    private function edit(BotService $service, array $extra = [])
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->patch(route('services.update', $service), [
                'name' => $service->name,
                'platform' => $service->platform,
                'my_price' => (string) $service->my_price,
                'min_quantity' => $service->min_quantity,
                'max_quantity' => $service->max_quantity,
                ...$extra,
            ]);
    }

    public function test_editing_a_service_changes_what_customers_are_told(): void
    {
        $service = BotService::factory()->for($this->tenant)->create();

        $this->edit($service, ['quality' => 'Premium', 'drop_info' => 'No drop', 'refill_info' => 'Lifetime'])
            ->assertSessionHasNoErrors();

        $service = $service->fresh();
        $this->assertSame('Premium', $service->quality);
        $this->assertSame('No drop', $service->drop_info);
        $this->assertSame('Lifetime', $service->refill_info);
    }
    public function test_clearing_a_field_removes_the_line(): void
    {
        $service = BotService::factory()->for($this->tenant)->create(['quality' => 'Premium', 'speed' => 'Fast']);

        $this->edit($service, ['quality' => '', 'speed' => ''])->assertSessionHasNoErrors();

        $this->assertNull($service->fresh()->quality);
        $this->assertNull($service->fresh()->speed);
    }

    public function test_the_table_row_carries_what_the_edit_form_needs_to_load(): void
    {
        $service = BotService::factory()->for($this->tenant)->create([
            'quality' => 'Premium',
            'speed' => 'Instant',
            'drop_info' => 'No drop',
            'refill_info' => '30 days',
            'link_instructions' => 'Send your profile link',
        ]);

        $row = ServiceQuery::toRow($service);

        $this->assertSame('Premium', $row['quality']);
        $this->assertSame('Instant', $row['speed']);
        $this->assertSame('No drop', $row['dropInfo']);
        $this->assertSame('30 days', $row['refillInfo']);
        // So that saving an edit does not write the link help back as empty.
        $this->assertSame('Send your profile link', $row['linkInstructions']);
    }
    // ---- importing -------------------------------------------------------

    private function panel(): TenantPanel
    {
        return TenantPanel::factory()->for($this->tenant)->create();
    }

    private function import(TenantPanel $panel, string $name, string $id = '101')
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('onboarding.services.store'), [
                'panel_id' => $panel->id,
                'services' => [[
                    'provider_service_id' => $id,
                    'name' => $name,
                    'platform' => 'Instagram',
                    'category' => 'Followers',
                    'cost_price' => '2.0',
                    'my_price' => '2.6',
                    'min_quantity' => 100,
                    'max_quantity' => 50000,
                ]],
            ]);
    }

    public function test_an_imported_service_gets_the_promises_its_name_makes(): void
    {
        $this->import($this->panel(), 'Instagram Followers | Real | No Drop | 365 Days Refill')
            ->assertSessionHasNoErrors();

        $service = $this->saved();
        $this->assertSame('No drop', $service->drop_info);
        $this->assertSame('365 days', $service->refill_info);
    }

    public function test_a_name_that_promises_nothing_leaves_the_lines_blank(): void
    {
        $this->import($this->panel(), 'Instagram Followers Cheap');

        $service = $this->saved();
        $this->assertNull($service->drop_info);
        $this->assertNull($service->refill_info);
    }

    public function test_importing_again_does_not_overwrite_what_the_reseller_set(): void
    {
        $panel = $this->panel();
        $this->import($panel, 'Instagram Followers | No Drop | 30 Days Refill');

        $this->saved()->update(['drop_info' => 'Low drop', 'refill_info' => 'Lifetime, free']);

        $this->import($panel, 'Instagram Followers | No Drop | 30 Days Refill');

        $service = $this->saved();
        $this->assertSame('Low drop', $service->drop_info);
        $this->assertSame('Lifetime, free', $service->refill_info);
    }
}
