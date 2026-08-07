<?php

namespace Tests\Feature;

use App\Models\BotService;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The providers screen.
 *
 * Three things carry real risk: the plan limit, which is the only thing
 * stopping a reseller running more panels than they pay for; removal, which
 * must not orphan the services a panel supplied; and the API key, which must
 * never travel back to the browser.
 */
class OrderBotProvidersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function panel(array $attributes = []): TenantPanel
    {
        return TenantPanel::factory()->for($this->tenant)->create($attributes);
    }

    /** A panel that answers detection with a balance and a service list. */
    private function fakePanelUp(): void
    {
        Http::fake([
            '*' => Http::response(['balance' => '84.20', 'currency' => 'USD']),
        ]);
    }

    private function fakePanelDown(): void
    {
        Http::fake([
            '*' => Http::response('nope', 500),
        ]);
    }

    /**
     * Set the cap on the row the controller actually reads.
     *
     * `RefreshDatabase` leaves no plans behind, so this creates one when there
     * is none — an `?->update()` on a missing plan would do nothing quietly and
     * leave the test asserting against the controller's fallback of 1.
     */
    private function setMaxPanels(int $max): void
    {
        $plan = Plan::forService('order_bot');

        if ($plan === null) {
            Plan::create([
                'code' => 'order_bot_test',
                'name' => 'Order Bot',
                'service_key' => 'order_bot',
                'price_monthly' => 10,
                'currency' => 'USD',
                'max_panels' => $max,
                'status' => 'active',
                'sort_order' => 1,
            ]);

            return;
        }

        $plan->update(['max_panels' => $max]);
    }

    public function test_it_lists_the_tenants_panels(): void
    {
        $this->panel(['name' => 'Main panel']);

        $this->actingAs($this->tenant)
            ->get(route('order-bot.providers'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('OrderBot/Providers')
                ->has('providers', 1)
                ->where('providers.0.name', 'Main panel'),
            );
    }

    public function test_it_does_not_leak_another_tenants_panels(): void
    {
        $other = Tenant::factory()->create();
        TenantPanel::factory()->for($other)->create();

        $this->actingAs($this->tenant)
            ->get(route('order-bot.providers'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('providers', 0));
    }

    /** The key is write-only; nothing about it may reach the page. */
    public function test_the_api_key_is_never_sent_to_the_browser(): void
    {
        $this->panel(['api_key_enc' => 'super-secret-key']);

        $response = $this->actingAs($this->tenant)->get(route('order-bot.providers'));

        $response->assertOk();
        $response->assertDontSee('super-secret-key');
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('providers.0.api_key_enc')
            ->missing('providers.0.apiKey'),
        );
    }

    public function test_it_counts_imported_services_per_panel(): void
    {
        $panel = $this->panel();
        BotService::factory()->count(3)->for($this->tenant)->create(['panel_id' => $panel->id]);

        $this->actingAs($this->tenant)
            ->get(route('order-bot.providers'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('providers.0.importedServices', 3),
            );
    }

    public function test_it_reports_the_plan_limit(): void
    {
        $this->setMaxPanels(2);
        $this->panel();

        $this->actingAs($this->tenant)
            ->get(route('order-bot.providers'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('limit.max', 2)
                ->where('limit.used', 1)
                ->where('limit.reached', false),
            );
    }

    public function test_the_limit_is_reached_when_the_plan_is_full(): void
    {
        $this->setMaxPanels(1);
        $this->panel();

        $this->actingAs($this->tenant)
            ->get(route('order-bot.providers'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('limit.reached', true));
    }

    /**
     * The page hides the form at the limit, but the server is what has to
     * hold — a stale page or a direct post must not get past it.
     */
    public function test_it_refuses_a_panel_beyond_the_plan_limit(): void
    {
        $this->setMaxPanels(1);
        $this->panel();
        $this->fakePanelUp();

        $this->actingAs($this->tenant)
            ->post(route('order-bot.providers.store'), [
                'name' => 'Second panel',
                'api_url' => 'https://second.example',
                'api_key' => 'key',
            ])
            ->assertSessionHasErrors('api_url');

        $this->assertSame(1, TenantPanel::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_it_connects_a_panel_that_answers(): void
    {
        $this->setMaxPanels(5);
        $this->fakePanelUp();

        $this->actingAs($this->tenant)
            ->post(route('order-bot.providers.store'), [
                'name' => 'My Panel',
                'api_url' => 'https://panel.example',
                'api_key' => 'the-key',
            ])
            ->assertRedirect();

        $panel = TenantPanel::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        $this->assertSame('My Panel', $panel->name);
        $this->assertSame('active', $panel->status);
        // Stored encrypted, but readable back through the cast.
        $this->assertSame('the-key', $panel->api_key_enc);
    }

    public function test_it_refuses_a_panel_that_does_not_answer(): void
    {
        $this->setMaxPanels(5);
        $this->fakePanelDown();

        $this->actingAs($this->tenant)
            ->post(route('order-bot.providers.store'), [
                'name' => 'Dead panel',
                'api_url' => 'https://dead.example',
                'api_key' => 'key',
            ])
            ->assertSessionHasErrors('api_url');

        $this->assertSame(0, TenantPanel::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_refresh_marks_a_panel_that_stopped_answering(): void
    {
        $panel = $this->panel(['status' => 'active']);
        $this->fakePanelDown();

        $this->actingAs($this->tenant)
            ->post(route('order-bot.providers.refresh', $panel))
            ->assertRedirect();

        $this->assertSame('error', $panel->fresh()->status);
    }

    public function test_refresh_restores_a_panel_that_came_back(): void
    {
        $panel = $this->panel(['status' => 'error']);
        $this->fakePanelUp();

        $this->actingAs($this->tenant)
            ->post(route('order-bot.providers.refresh', $panel))
            ->assertRedirect();

        $fresh = $panel->fresh();

        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->last_checked_at);
    }

    /**
     * Removing a panel must not destroy the reseller's own pricing, and must
     * not leave the bot selling things it can no longer buy.
     */
    public function test_removing_a_panel_hides_its_services_without_deleting_them(): void
    {
        $panel = $this->panel();
        $services = BotService::factory()->count(2)->for($this->tenant)->create([
            'panel_id' => $panel->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->tenant)
            ->delete(route('order-bot.providers.destroy', $panel))
            ->assertRedirect();

        $this->assertDatabaseMissing('tenant_panels', ['id' => $panel->id]);

        // Looked up by id, not by panel_id: the foreign key is nullOnDelete,
        // so the surviving rows no longer point at the panel that is gone.
        $survivors = BotService::withoutTenantScope()
            ->whereIn('id', $services->pluck('id'))
            ->get();

        $this->assertCount(2, $survivors);
        $this->assertTrue($survivors->every(fn (BotService $s) => $s->status === 'hidden'));
        $this->assertTrue($survivors->every(fn (BotService $s) => $s->panel_id === null));
    }

    public function test_it_cannot_remove_another_tenants_panel(): void
    {
        $other = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($other)->create();

        $this->actingAs($this->tenant)
            ->delete(route('order-bot.providers.destroy', $panel))
            ->assertNotFound();

        $this->assertDatabaseHas('tenant_panels', ['id' => $panel->id]);
    }

    public function test_it_cannot_refresh_another_tenants_panel(): void
    {
        $other = Tenant::factory()->create();
        $panel = TenantPanel::factory()->for($other)->create(['status' => 'active']);

        $this->actingAs($this->tenant)
            ->post(route('order-bot.providers.refresh', $panel))
            ->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $this->get(route('order-bot.providers'))->assertRedirect(route('login'));
    }

    /** `/order-bot/providers` must not be swallowed by the `{tab}` route. */
    public function test_the_providers_url_is_not_read_as_a_tab(): void
    {
        $this->actingAs($this->tenant)
            ->get('/order-bot/providers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('OrderBot/Providers'));
    }
}
