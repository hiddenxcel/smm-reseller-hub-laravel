<?php

namespace Tests\Feature;

use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\PricingRule;
use App\Models\ServicePriceHistory;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Catalogue\BulkAdjustment;
use App\Services\Catalogue\PanelSync;
use App\Services\Catalogue\PricingEngine;
use App\Services\Catalogue\ServiceFilters;
use App\Services\Catalogue\ServiceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The services screen is where a reseller's margin lives, so the arithmetic
 * gets the most attention here: prices are per 1,000 and stored to four
 * decimal places, and a rounding error at that scale is money.
 */
class ServicesPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function service(array $attributes = []): BotService
    {
        return BotService::factory()->for($this->tenant)->create($attributes);
    }

    private function filters(array $query = []): ServiceFilters
    {
        return ServiceFilters::fromRequest(Request::create('/services', 'GET', $query));
    }

    private function query(): ServiceQuery
    {
        return ServiceQuery::for($this->tenant->fresh());
    }

    private function engine(): PricingEngine
    {
        return PricingEngine::for($this->tenant->fresh());
    }

    private function rule(array $attributes = []): PricingRule
    {
        return PricingRule::factory()->for($this->tenant)->create($attributes);
    }

    // --- Access -----------------------------------------------------------

    public function test_services_requires_a_logged_in_reseller(): void
    {
        $this->get(route('services.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_the_tenants_services(): void
    {
        $this->service(['name' => 'Instagram Followers']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Services/Index')
                ->has('services.data', 1)
                ->where('services.data.0.name', 'Instagram Followers'));
    }

    public function test_another_resellers_services_are_never_listed(): void
    {
        BotService::factory()->for(Tenant::factory()->create())->create();
        $mine = $this->service();

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('services.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('services.data', 1)
                ->where('services.data.0.id', $mine->id));
    }

    public function test_repricing_another_resellers_service_is_not_found(): void
    {
        $theirs = BotService::factory()
            ->for(Tenant::factory()->create())
            ->create(['my_price' => '2.0000']);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('services.act', $theirs->id), ['action' => 'price', 'price' => '99'])
            ->assertNotFound();

        $this->assertSame('2.0000', $theirs->fresh()->my_price);
    }

    // --- Profit and margin -------------------------------------------------

    public function test_profit_and_margin_are_computed_from_cost(): void
    {
        $service = $this->service(['cost_price' => '1.5000', 'my_price' => '2.0000']);

        $this->assertSame('0.5000', $service->profit());
        $this->assertSame(25.0, $service->margin());
        $this->assertFalse($service->isUnderwater());
    }

    /**
     * A service the panel never priced has an unknown margin, not a zero one.
     * Showing 0% would send a reseller hunting for a problem that is not there.
     */
    public function test_a_service_without_a_cost_has_no_margin(): void
    {
        $service = $this->service(['cost_price' => null]);

        $this->assertNull($service->profit());
        $this->assertNull($service->margin());
        $this->assertFalse($service->isUnderwater());
    }

    public function test_a_service_priced_below_cost_is_flagged(): void
    {
        $service = $this->service(['cost_price' => '3.0000', 'my_price' => '2.0000']);

        $this->assertTrue($service->isUnderwater());
        $this->assertSame('-1.0000', $service->profit());
    }

    /** Costs can be fractions of a cent; the maths must survive that scale. */
    public function test_tiny_prices_keep_their_precision(): void
    {
        $service = $this->service(['cost_price' => '0.0009', 'my_price' => '0.0012']);

        $this->assertSame('0.0003', $service->profit());
    }

    // --- Filters and search ------------------------------------------------

    public function test_the_margin_filter_separates_losses_from_unknowns(): void
    {
        $loss = $this->service(['cost_price' => '3.0000', 'my_price' => '2.0000']);
        $unknown = $this->service(['cost_price' => null, 'my_price' => '2.0000']);
        $this->service(['cost_price' => '1.0000', 'my_price' => '2.0000']);

        $losses = $this->query()->paginate($this->filters(['margin' => 'loss']));
        $unknowns = $this->query()->paginate($this->filters(['margin' => 'unknown']));

        $this->assertCount(1, $losses->items());
        $this->assertSame($loss->id, $losses->items()[0]->id);
        $this->assertCount(1, $unknowns->items());
        $this->assertSame($unknown->id, $unknowns->items()[0]->id);
    }

    public function test_search_finds_a_service_by_name_panel_id_or_platform(): void
    {
        $byName = $this->service(['name' => 'TikTok Views HQ']);
        $byPanelId = $this->service(['provider_service_id' => '55512']);
        $byPlatform = $this->service(['platform' => 'Spotify', 'name' => 'Plays']);

        foreach ([
            'tiktok' => $byName,
            '55512' => $byPanelId,
            'spotify' => $byPlatform,
        ] as $term => $expected) {
            $results = $this->query()->paginate($this->filters(['q' => (string) $term]));

            $this->assertCount(1, $results->items(), "searching '{$term}'");
            $this->assertSame($expected->id, $results->items()[0]->id);
        }
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $this->service(['name' => 'Instagram Followers']);

        $this->assertCount(0, $this->query()->paginate($this->filters(['q' => '%']))->items());
    }

    public function test_sorting_is_limited_to_known_columns(): void
    {
        $filters = $this->filters(['sort' => 'my_price) --', 'dir' => 'sideways']);

        $this->assertSame('name', $filters->sort);
        $this->assertSame('asc', $filters->direction);

        $this->query()->paginate($filters); // would throw if it reached SQL
    }

    /** Unknown margins sort last — an unknown is not a small number. */
    public function test_services_without_a_cost_sort_last_by_margin(): void
    {
        $this->service(['name' => 'No cost', 'cost_price' => null]);
        $this->service(['name' => 'Has cost', 'cost_price' => '1.0000', 'my_price' => '2.0000']);

        $rows = $this->query()->paginate($this->filters(['sort' => 'margin', 'dir' => 'desc']));

        $this->assertSame('Has cost', $rows->items()[0]->name);
        $this->assertSame('No cost', $rows->items()[1]->name);
    }

    public function test_the_platform_sidebar_counts_match_the_filter(): void
    {
        BotService::factory()->for($this->tenant)->count(3)->create(['platform' => 'Instagram']);
        BotService::factory()->for($this->tenant)->count(2)->create(['platform' => 'TikTok']);

        $counts = collect($this->query()->platformCounts($this->filters()))
            ->keyBy('platform');

        $this->assertSame(3, $counts['Instagram']['services']);
        $this->assertSame(2, $counts['TikTok']['services']);
    }

    public function test_the_kpis_report_the_whole_catalogue(): void
    {
        $this->service(['status' => BotService::ACTIVE, 'cost_price' => '1.0000', 'my_price' => '2.0000']);
        $this->service(['status' => BotService::HIDDEN]);
        $this->service(['status' => BotService::PAUSED, 'cost_price' => '3.0000', 'my_price' => '2.0000']);

        $kpis = $this->query()->kpis();

        $this->assertSame(3, $kpis['total']);
        $this->assertSame(1, $kpis['active']);
        $this->assertSame(1, $kpis['hidden']);
        $this->assertSame(1, $kpis['paused']);
        // The one selling below cost.
        $this->assertSame(1, $kpis['underwater']);
    }

    // --- Manual price changes ----------------------------------------------

    public function test_changing_a_price_records_why(): void
    {
        $service = $this->service(['my_price' => '2.0000']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.act', $service->id), ['action' => 'price', 'price' => '3.5'])
            ->assertSessionHas('success');

        $this->assertSame('3.5000', $service->fresh()->my_price);
        $this->assertDatabaseHas('service_price_history', [
            'service_id' => $service->id,
            'reason' => ServicePriceHistory::MANUAL,
            'old_price' => '2.0000',
            'new_price' => '3.5000',
        ]);
    }

    /** A zero price would have the bot sell for nothing. */
    public function test_a_zero_price_is_refused(): void
    {
        $service = $this->service(['my_price' => '2.0000']);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('services.act', $service->id), ['action' => 'price', 'price' => '0'])
            ->assertSessionHasErrors('price');

        $this->assertSame('2.0000', $service->fresh()->my_price);
    }

    public function test_editing_a_name_alone_leaves_no_price_history(): void
    {
        $service = $this->service(['name' => 'Old name', 'my_price' => '2.0000']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->patch(route('services.update', $service->id), [
                'name' => 'New name',
                'platform' => $service->platform,
                'my_price' => '2.0000',
                'min_quantity' => 100,
                'max_quantity' => 100000,
            ])
            ->assertSessionHas('success');

        $this->assertSame('New name', $service->fresh()->name);
        $this->assertDatabaseMissing('service_price_history', ['service_id' => $service->id]);
    }

    // --- Status -------------------------------------------------------------

    public function test_the_three_statuses_can_be_set(): void
    {
        $service = $this->service();

        foreach ([BotService::PAUSED, BotService::HIDDEN, BotService::ACTIVE] as $status) {
            $this->actingAs($this->tenant, 'tenant')
                ->from(route('services.index'))
                ->post(route('services.act', $service->id), [
                    'action' => 'status',
                    'status' => $status,
                ])
                ->assertSessionHas('success');

            $this->assertSame($status, $service->fresh()->status);
        }
    }

    /**
     * The sync lifts pauses it applied itself. A deliberate pause must survive,
     * or the platform is overruling a decision someone made on purpose.
     */
    public function test_a_deliberate_pause_clears_the_auto_flag(): void
    {
        $service = $this->service(['status' => BotService::ACTIVE, 'auto_paused' => true]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.act', $service->id), [
                'action' => 'status',
                'status' => BotService::PAUSED,
            ]);

        $fresh = $service->fresh();

        $this->assertSame(BotService::PAUSED, $fresh->status);
        $this->assertFalse($fresh->auto_paused);
    }

    public function test_duplicating_a_service_lands_hidden(): void
    {
        $service = $this->service(['name' => 'Instagram Followers', 'featured' => true]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.act', $service->id), ['action' => 'duplicate'])
            ->assertSessionHas('success');

        $copy = BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Instagram Followers (copy)')
            ->first();

        $this->assertNotNull($copy);
        $this->assertSame(BotService::HIDDEN, $copy->status);
        $this->assertFalse($copy->featured);
    }

    // --- Bulk pricing --------------------------------------------------------

    public function test_a_percentage_increase_hits_every_selected_price(): void
    {
        $first = $this->service(['my_price' => '2.0000']);
        $second = $this->service(['my_price' => '10.0000']);

        $services = BotService::withoutTenantScope()
            ->whereIn('id', [$first->id, $second->id])
            ->get();

        $this->engine()->applyBulk(
            $services,
            BulkAdjustment::make('percent', '10.0000'),
            'test',
        );

        $this->assertSame('2.2000', $first->fresh()->my_price);
        $this->assertSame('11.0000', $second->fresh()->my_price);
    }

    /**
     * The preview and the apply must agree exactly — a preview computed by a
     * second implementation is how the two drift apart.
     */
    public function test_the_preview_matches_what_apply_writes(): void
    {
        $service = $this->service(['my_price' => '3.3300', 'cost_price' => '1.0000']);

        $services = BotService::withoutTenantScope()->whereKey($service->id)->get();
        $adjustment = BulkAdjustment::make('percent', '7.5000');

        $preview = $this->engine()->previewBulk($services, $adjustment);
        $this->engine()->applyBulk($services, $adjustment, 'test');

        $this->assertSame($preview[0]['to'], $service->fresh()->my_price);
    }

    /** The floor is what stops a careless sweep selling below cost. */
    public function test_a_minimum_profit_floor_stops_a_loss(): void
    {
        $service = $this->service(['cost_price' => '5.0000', 'my_price' => '6.0000']);

        $services = BotService::withoutTenantScope()->whereKey($service->id)->get();

        $this->engine()->applyBulk(
            $services,
            BulkAdjustment::make('percent', '50.0000', decrease: true, minProfit: '0.5000'),
            'test',
        );

        // -50% would be 3.00, below the 5.00 cost. The floor holds it at 5.50.
        $this->assertSame('5.5000', $service->fresh()->my_price);
    }

    public function test_a_bulk_change_records_each_price(): void
    {
        $service = $this->service(['my_price' => '2.0000']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.pricing.apply'), [
                'mode' => 'percent',
                'amount' => '25',
                'ids' => [$service->id],
            ])
            ->assertSessionHas('success');

        $this->assertSame('2.5000', $service->fresh()->my_price);
        $this->assertDatabaseHas('service_price_history', [
            'service_id' => $service->id,
            'reason' => ServicePriceHistory::BULK,
        ]);
    }

    public function test_bulk_pricing_cannot_reach_another_resellers_services(): void
    {
        $theirs = BotService::factory()
            ->for(Tenant::factory()->create())
            ->create(['my_price' => '2.0000']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.pricing.apply'), [
                'mode' => 'percent',
                'amount' => '50',
                'ids' => [$theirs->id],
            ]);

        $this->assertSame('2.0000', $theirs->fresh()->my_price);
    }

    // --- Markup rules ---------------------------------------------------------

    public function test_a_percent_rule_prices_from_cost(): void
    {
        $rule = $this->rule(['mode' => PricingRule::PERCENT, 'amount' => '30.0000']);
        $service = $this->service(['cost_price' => '2.0000', 'my_price' => '2.0000']);

        $this->assertSame('2.6000', $this->engine()->priceFromRules($service));
        $this->assertSame('2.6000', $this->engine()->applyRule($rule, '2.0000'));
    }

    public function test_a_fixed_rule_adds_a_flat_amount(): void
    {
        $rule = $this->rule(['mode' => PricingRule::FIXED, 'amount' => '0.5000']);

        $this->assertSame('2.5000', $this->engine()->applyRule($rule, '2.0000'));
    }

    public function test_a_multiplier_rule_scales_the_cost(): void
    {
        $rule = $this->rule(['mode' => PricingRule::MULTIPLIER, 'amount' => '1.4000']);

        $this->assertSame('2.8000', $this->engine()->applyRule($rule, '2.0000'));
    }

    /**
     * A percentage markup on a very cheap service earns almost nothing. The
     * floor is what a reseller means by "never less than 20 cents".
     */
    public function test_a_minimum_profit_lifts_a_thin_markup(): void
    {
        $rule = $this->rule([
            'mode' => PricingRule::PERCENT,
            'amount' => '30.0000',
            'min_profit' => '0.2000',
        ]);

        // +30% of 0.02 is 0.006 profit; the floor makes it 0.22.
        $this->assertSame('0.2200', $this->engine()->applyRule($rule, '0.0200'));
    }

    public function test_a_maximum_profit_caps_a_fat_markup(): void
    {
        $rule = $this->rule([
            'mode' => PricingRule::PERCENT,
            'amount' => '100.0000',
            'max_profit' => '5.0000',
        ]);

        $this->assertSame('105.0000', $this->engine()->applyRule($rule, '100.0000'));
    }

    /** Rounding goes up: a rule protecting margin must never shave it. */
    public function test_rounding_never_rounds_a_price_down(): void
    {
        $rule = $this->rule([
            'mode' => PricingRule::PERCENT,
            'amount' => '30.0000',
            'round_to' => '0.5000',
        ]);

        // 2.60 rounds up to 3.00, not down to 2.50.
        $this->assertSame('3.0000', $this->engine()->applyRule($rule, '2.0000'));
    }

    /** First match wins — two markups stacking is never what anyone means. */
    public function test_only_the_first_matching_rule_applies(): void
    {
        $this->rule([
            'name' => 'Instagram',
            'platform' => 'Instagram',
            'mode' => PricingRule::PERCENT,
            'amount' => '30.0000',
            'sort_order' => 0,
        ]);
        $this->rule([
            'name' => 'Everything',
            'platform' => null,
            'mode' => PricingRule::PERCENT,
            'amount' => '100.0000',
            'sort_order' => 1,
        ]);

        $service = $this->service(['platform' => 'Instagram', 'cost_price' => '2.0000']);

        $this->assertSame('2.6000', $this->engine()->priceFromRules($service));
    }

    public function test_a_rule_for_another_platform_does_not_apply(): void
    {
        $this->rule(['platform' => 'TikTok', 'mode' => PricingRule::PERCENT, 'amount' => '30.0000']);

        $service = $this->service(['platform' => 'Instagram', 'cost_price' => '2.0000']);

        $this->assertNull($this->engine()->priceFromRules($service));
    }

    public function test_applying_rules_by_hand_updates_prices(): void
    {
        $this->rule(['mode' => PricingRule::PERCENT, 'amount' => '50.0000']);
        $service = $this->service(['cost_price' => '2.0000', 'my_price' => '2.1000']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.rules.apply'))
            ->assertSessionHas('success');

        $this->assertSame('3.0000', $service->fresh()->my_price);
        $this->assertDatabaseHas('service_price_history', [
            'service_id' => $service->id,
            'reason' => ServicePriceHistory::RULE,
        ]);
    }

    // --- Panel sync -----------------------------------------------------------

    public function test_a_cost_change_reprices_when_a_rule_covers_it(): void
    {
        Http::fake(['*' => Http::response([
            ['service' => '900', 'name' => 'Instagram Followers', 'rate' => '3.0000', 'min' => 100, 'max' => 50000],
        ])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $this->rule(['mode' => PricingRule::PERCENT, 'amount' => '50.0000']);

        $service = $this->service([
            'panel_id' => $panel->id,
            'provider_service_id' => '900',
            'cost_price' => '2.0000',
            'my_price' => '3.0000',
        ]);

        $result = PanelSync::for($this->tenant)->run($panel);

        $fresh = $service->fresh();

        $this->assertFalse($result['failed']);
        $this->assertSame('3.0000', $fresh->cost_price);
        // Cost moved 2 -> 3, and the +50% rule follows it.
        $this->assertSame('4.5000', $fresh->my_price);
        $this->assertSame(1, $result['repriced']);
    }

    /**
     * Without a rule, a reseller's own price stands. A sync that quietly moved
     * a hand-set price would be the platform overruling them.
     */
    public function test_a_cost_change_leaves_a_hand_set_price_alone(): void
    {
        Http::fake(['*' => Http::response([
            ['service' => '900', 'name' => 'Instagram Followers', 'rate' => '3.0000', 'min' => 100, 'max' => 50000],
        ])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $service = $this->service([
            'panel_id' => $panel->id,
            'provider_service_id' => '900',
            'cost_price' => '2.0000',
            'my_price' => '5.0000',
        ]);

        PanelSync::for($this->tenant)->run($panel);

        $fresh = $service->fresh();

        $this->assertSame('3.0000', $fresh->cost_price);
        $this->assertSame('5.0000', $fresh->my_price);
        // Still recorded, so the shrinking margin can be explained later.
        $this->assertDatabaseHas('service_price_history', [
            'service_id' => $service->id,
            'reason' => ServicePriceHistory::SYNC,
        ]);
    }

    /** A service the panel drops is paused, never deleted — orders need it. */
    public function test_a_service_missing_from_the_panel_is_paused_not_deleted(): void
    {
        Http::fake(['*' => Http::response([])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $service = $this->service([
            'panel_id' => $panel->id,
            'provider_service_id' => '900',
            'status' => BotService::ACTIVE,
        ]);

        $result = PanelSync::for($this->tenant)->run($panel);

        $fresh = $service->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(BotService::PAUSED, $fresh->status);
        $this->assertTrue($fresh->auto_paused);
        $this->assertSame(1, $result['paused']);
    }

    public function test_a_service_that_reappears_is_switched_back_on(): void
    {
        Http::fake(['*' => Http::response([
            ['service' => '900', 'name' => 'Back', 'rate' => '2.0000', 'min' => 100, 'max' => 5000],
        ])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $service = $this->service([
            'panel_id' => $panel->id,
            'provider_service_id' => '900',
            'status' => BotService::PAUSED,
            'auto_paused' => true,
        ]);

        PanelSync::for($this->tenant)->run($panel);

        $this->assertSame(BotService::ACTIVE, $service->fresh()->status);
    }

    public function test_a_pause_the_reseller_chose_survives_a_sync(): void
    {
        Http::fake(['*' => Http::response([
            ['service' => '900', 'name' => 'Still here', 'rate' => '2.0000', 'min' => 100, 'max' => 5000],
        ])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $service = $this->service([
            'panel_id' => $panel->id,
            'provider_service_id' => '900',
            'status' => BotService::PAUSED,
            'auto_paused' => false,
        ]);

        PanelSync::for($this->tenant)->run($panel);

        $this->assertSame(BotService::PAUSED, $service->fresh()->status);
    }

    public function test_the_sync_follows_the_panels_quantity_limits(): void
    {
        Http::fake(['*' => Http::response([
            ['service' => '900', 'name' => 'Limits moved', 'rate' => '2.0000', 'min' => 500, 'max' => 20000],
        ])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $service = $this->service([
            'panel_id' => $panel->id,
            'provider_service_id' => '900',
            'min_quantity' => 100,
            'max_quantity' => 100000,
        ]);

        PanelSync::for($this->tenant)->run($panel);

        $fresh = $service->fresh();

        $this->assertSame(500, $fresh->min_quantity);
        $this->assertSame(20000, $fresh->max_quantity);
    }

    public function test_a_panel_that_cannot_be_read_reports_a_failure(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid API key'])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();

        $result = PanelSync::for($this->tenant)->run($panel);

        $this->assertTrue($result['failed']);
        $this->assertStringContainsString('Invalid API key', $result['message']);
    }

    // --- Drawer tabs -----------------------------------------------------------

    public function test_each_tab_returns_only_its_own_data(): void
    {
        $service = $this->service();

        foreach (['overview', 'pricing', 'orders', 'logs', 'settings'] as $tab) {
            $this->actingAs($this->tenant, 'tenant')
                ->getJson(route('services.show', [$service->id, $tab]))
                ->assertOk()
                ->assertJsonStructure([$tab]);
        }
    }

    /**
     * Orders record the PANEL's service id, not ours. Keying on the wrong one
     * would silently show zero orders for every service.
     */
    public function test_the_orders_tab_matches_on_the_panels_service_id(): void
    {
        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $service = $this->service([
            'panel_id' => $panel->id,
            'provider_service_id' => '900',
        ]);

        BotOrder::factory()->for($this->tenant)->create([
            'panel_id' => $panel->id,
            'service_id' => '900',
            'service_name' => 'Instagram Followers',
        ]);
        BotOrder::factory()->for($this->tenant)->create([
            'panel_id' => $panel->id,
            'service_id' => '901',
        ]);

        $orders = $this->actingAs($this->tenant, 'tenant')
            ->getJson(route('services.show', [$service->id, 'orders']))
            ->json('orders');

        $this->assertCount(1, $orders);
    }

    // --- Bulk actions -----------------------------------------------------------

    public function test_a_bulk_status_change_skips_what_already_matches(): void
    {
        $active = $this->service(['status' => BotService::ACTIVE]);
        $alreadyHidden = $this->service(['status' => BotService::HIDDEN]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.bulk'), [
                'action' => 'hide',
                'ids' => [$active->id, $alreadyHidden->id],
            ])
            ->assertSessionHas('success');

        $this->assertSame(BotService::HIDDEN, $active->fresh()->status);
    }

    public function test_a_bulk_action_cannot_reach_another_resellers_services(): void
    {
        $theirs = BotService::factory()
            ->for(Tenant::factory()->create())
            ->create(['status' => BotService::ACTIVE]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.bulk'), ['action' => 'hide', 'ids' => [$theirs->id]]);

        $this->assertSame(BotService::ACTIVE, $theirs->fresh()->status);
    }

    public function test_select_all_matching_returns_only_the_filtered_ids(): void
    {
        $paused = $this->service(['status' => BotService::PAUSED]);
        $this->service(['status' => BotService::ACTIVE]);

        $this->actingAs($this->tenant, 'tenant')
            ->getJson(route('services.matching-ids', ['status' => 'paused']))
            ->assertOk()
            ->assertJson(['ids' => [$paused->id]]);
    }

    // --- Adding and exporting ----------------------------------------------------

    public function test_adding_a_service_records_its_opening_price(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.store'), [
                'name' => 'Hand-added',
                'platform' => 'Instagram',
                'provider_service_id' => '4242',
                'my_price' => '2.5',
                'min_quantity' => 100,
                'max_quantity' => 10000,
            ])
            ->assertSessionHas('success');

        $service = BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Hand-added')
            ->first();

        $this->assertNotNull($service);
        $this->assertDatabaseHas('service_price_history', [
            'service_id' => $service->id,
            'reason' => ServicePriceHistory::IMPORT,
        ]);
    }

    public function test_a_service_cannot_be_attached_to_another_resellers_panel(): void
    {
        $theirPanel = TenantPanel::factory()
            ->for(Tenant::factory()->create())
            ->create();

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.store'), [
                'name' => 'Sneaky',
                'platform' => 'Instagram',
                'provider_service_id' => '4242',
                'panel_id' => $theirPanel->id,
                'my_price' => '2.5',
                'min_quantity' => 100,
                'max_quantity' => 10000,
            ]);

        $service = BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Sneaky')
            ->first();

        $this->assertNotNull($service);
        $this->assertNull($service->panel_id);
    }

    public function test_the_export_streams_the_filtered_rows(): void
    {
        $this->service(['name' => 'Paused one', 'status' => BotService::PAUSED]);
        $this->service(['name' => 'Active one', 'status' => BotService::ACTIVE]);

        $response = $this->actingAs($this->tenant, 'tenant')
            ->get(route('services.export', ['status' => 'paused']));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Paused one', $csv);
        $this->assertStringNotContainsString('Active one', $csv);
    }
}
