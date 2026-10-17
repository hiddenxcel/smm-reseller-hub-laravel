<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Catalogue\ServiceActions;
use App\Services\Catalogue\ServiceLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WhatsApp draws ten rows in a list, so a reseller may show at most ten
 * services in any one category — ten Instagram Likes, ten Instagram Followers,
 * ten TikTok Likes — and cannot add an eleventh until they delete or hide one.
 */
class ServiceLimitTest extends TestCase
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

    private function fill(string $platform, string $category, int $count = 10, string $state = 'active'): void
    {
        BotService::factory()->count($count)->on($platform, $category)->create([
            'tenant_id' => $this->tenant->id,
            'panel_id' => $this->panel->id,
            'status' => $state,
        ]);
    }

    private function newService(array $overrides = []): array
    {
        return [
            'name' => 'Instagram Likes Fast',
            'platform' => 'Instagram',
            'category' => 'Likes',
            'provider_service_id' => '9001',
            'my_price' => '2.00',
            'min_quantity' => 10,
            'max_quantity' => 1000,
            ...$overrides,
        ];
    }

    private function add(array $overrides = [])
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->post(route('services.store'), $this->newService($overrides));
    }

    public function test_the_tenth_service_in_a_category_is_accepted(): void
    {
        $this->fill('Instagram', 'Likes', 9);

        $this->add()->assertSessionHas('success');

        $this->assertSame(10, ServiceLimit::listed($this->tenant->id, 'Instagram', 'Likes'));
    }

    public function test_the_eleventh_is_refused_and_not_saved(): void
    {
        $this->fill('Instagram', 'Likes');

        $this->add()->assertSessionHas('error');

        $this->assertSame(10, BotService::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_refusal_says_what_to_do(): void
    {
        $this->fill('Instagram', 'Likes');

        $response = $this->add();

        $this->assertStringContainsString('Delete or hide', session('error'));
        $this->assertStringContainsString('Instagram Likes', session('error'));
    }

    public function test_each_category_has_its_own_ten(): void
    {
        $this->fill('Instagram', 'Likes');

        // Same platform, other category — and other platform, same category.
        $this->add(['category' => 'Followers', 'provider_service_id' => '9002'])->assertSessionHas('success');
        $this->add(['platform' => 'TikTok', 'provider_service_id' => '9003'])->assertSessionHas('success');
    }

    public function test_the_category_is_matched_whatever_its_capitals_or_spaces(): void
    {
        $this->fill('Instagram', 'Likes');

        $this->add(['platform' => ' instagram ', 'category' => 'LIKES'])->assertSessionHas('error');
    }

    public function test_hiding_a_service_makes_room(): void
    {
        $this->fill('Instagram', 'Likes');
        $one = BotService::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();

        ServiceActions::for($this->tenant)->setStatus($one, BotService::HIDDEN);

        $this->add()->assertSessionHas('success');
    }

    public function test_a_paused_service_still_takes_a_row(): void
    {
        $this->fill('Instagram', 'Likes', 10, BotService::PAUSED);

        $this->add()->assertSessionHas('error');
    }

    public function test_hidden_services_do_not_count(): void
    {
        $this->fill('Instagram', 'Likes', 15, BotService::HIDDEN);

        $this->add()->assertSessionHas('success');
    }

    public function test_showing_a_hidden_service_in_a_full_category_is_refused(): void
    {
        $this->fill('Instagram', 'Likes');
        $hidden = BotService::factory()->on('Instagram', 'Likes')->hidden()->create([
            'tenant_id' => $this->tenant->id,
            'panel_id' => $this->panel->id,
        ]);

        $outcome = ServiceActions::for($this->tenant)->setStatus($hidden, BotService::ACTIVE);

        $this->assertTrue($outcome->failed);
        $this->assertSame(BotService::HIDDEN, $hidden->fresh()->status);
    }

    public function test_changing_an_active_services_status_between_live_and_paused_is_always_fine(): void
    {
        $this->fill('Instagram', 'Likes');
        $one = BotService::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();

        $this->assertFalse(ServiceActions::for($this->tenant)->setStatus($one, BotService::PAUSED)->failed);
        $this->assertFalse(ServiceActions::for($this->tenant)->setStatus($one->fresh(), BotService::ACTIVE)->failed);
    }

    public function test_moving_a_service_into_a_full_category_is_refused(): void
    {
        $this->fill('Instagram', 'Likes');
        $mine = BotService::factory()->on('Instagram', 'Followers')->create([
            'tenant_id' => $this->tenant->id,
            'panel_id' => $this->panel->id,
        ]);

        $outcome = ServiceActions::for($this->tenant)->update($mine, [
            'name' => $mine->name,
            'platform' => 'Instagram',
            'category' => 'Likes',
            'my_price' => $mine->my_price,
        ]);

        $this->assertTrue($outcome->failed);
        $this->assertSame('Followers', $mine->fresh()->category);
    }

    public function test_editing_a_service_in_a_full_category_still_works(): void
    {
        $this->fill('Instagram', 'Likes');
        $one = BotService::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();

        $outcome = ServiceActions::for($this->tenant)->update($one, [
            'name' => 'Renamed',
            'platform' => 'Instagram',
            'category' => 'Likes',
            'my_price' => $one->my_price,
        ]);

        $this->assertFalse($outcome->failed);
        $this->assertSame('Renamed', $one->fresh()->name);
    }

    public function test_bulk_showing_stops_at_ten(): void
    {
        $this->fill('Instagram', 'Likes', 8);
        $hidden = BotService::factory()->count(5)->on('Instagram', 'Likes')->hidden()->create([
            'tenant_id' => $this->tenant->id,
            'panel_id' => $this->panel->id,
        ]);

        $result = ServiceActions::for($this->tenant)->runBulk('activate', $hidden->pluck('id')->all());

        $this->assertSame(2, $result['done']);
        $this->assertSame(10, ServiceLimit::listed($this->tenant->id, 'Instagram', 'Likes'));
    }

    public function test_another_resellers_services_do_not_count(): void
    {
        $other = Tenant::factory()->create();
        BotService::factory()->count(10)->on('Instagram', 'Likes')->create(['tenant_id' => $other->id]);

        $this->add()->assertSessionHas('success');
    }

    public function test_importing_from_the_panel_stops_at_ten_and_says_so(): void
    {
        $this->fill('Instagram', 'Likes', 9);

        $services = [];

        foreach (['101', '102', '103'] as $id) {
            $services[] = [
                'provider_service_id' => $id,
                'name' => "Likes {$id}",
                'platform' => 'Instagram',
                'category' => 'Likes',
                'my_price' => '2.00',
                'min_quantity' => 10,
                'max_quantity' => 1000,
            ];
        }

        $services[] = [
            'provider_service_id' => '201',
            'name' => 'TikTok Likes',
            'platform' => 'TikTok',
            'category' => 'Likes',
            'my_price' => '2.00',
            'min_quantity' => 10,
            'max_quantity' => 1000,
        ];

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('onboarding.services.store'), ['panel_id' => $this->panel->id, 'services' => $services])
            ->assertSessionHas('error')
            ->assertSessionHas('status', '2 services imported.');

        $this->assertSame(10, ServiceLimit::listed($this->tenant->id, 'Instagram', 'Likes'));
        $this->assertSame(1, ServiceLimit::listed($this->tenant->id, 'TikTok', 'Likes'));
    }

    public function test_reimporting_a_service_already_listed_is_not_blocked_by_a_full_category(): void
    {
        $this->fill('Instagram', 'Likes', 9);
        BotService::factory()->on('Instagram', 'Likes')->create([
            'tenant_id' => $this->tenant->id,
            'panel_id' => $this->panel->id,
            'provider_service_id' => '777',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('onboarding.services.store'), ['panel_id' => $this->panel->id, 'services' => [[
                'provider_service_id' => '777',
                'name' => 'Updated name',
                'platform' => 'Instagram',
                'category' => 'Likes',
                'my_price' => '3.00',
                'min_quantity' => 10,
                'max_quantity' => 1000,
            ]]])
            ->assertSessionHas('status', '1 service imported.')
            ->assertSessionMissing('error');
    }
}
