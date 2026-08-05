<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ResponseTemplate;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The wording every reseller starts with.
 *
 * The line this has to hold: a platform default is what a reseller sends when
 * they have NOT written their own. ResponseTemplate::resolve() picks the tenant
 * row first and falls back to the NULL row, so editing here must reach everyone
 * who has not overridden the key — and must not touch anyone who has.
 *
 * Getting that backwards would either silently rewrite resellers' own carefully
 * chosen wording, or change nothing at all while appearing to work.
 */
class AdminBotsTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->actingAs($this->admin, 'superadmin');
    }

    public function test_the_overview_loads_for_both_bots(): void
    {
        foreach (['order', 'support'] as $bot) {
            $this->get("/hx-control/bots/{$bot}")->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Admin/Bots/Index')
                    ->where('bot', $bot)
                    ->has('kpis'),
            );
        }
    }

    public function test_an_unknown_bot_is_a_404(): void
    {
        $this->get('/hx-control/bots/telegram')->assertNotFound();
    }

    public function test_the_templates_tab_lists_only_that_bots_keys(): void
    {
        $this->get('/hx-control/bots/support/templates')->assertInertia(
            function (AssertableInertia $page) {
                $bots = collect($page->toArray()['props']['templates'])->pluck('bot');

                $this->assertTrue($bots->every(fn ($bot) => $bot === 'support'));
                $this->assertGreaterThan(0, $bots->count());
            },
        );
    }

    public function test_a_platform_default_can_be_written(): void
    {
        $this->post('/hx-control/bots/templates', [
            'key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'bot' => 'support',
            'content' => 'Karibu! Chagua namba.',
        ])->assertRedirect();

        $this->assertDatabaseHas('response_templates', [
            'tenant_id' => null,
            'template_key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'content' => 'Karibu! Chagua namba.',
        ]);

        $this->assertDatabaseHas('activity_log', ['action' => 'templates.save']);
    }

    public function test_a_platform_default_reaches_a_reseller_who_has_not_overridden_it(): void
    {
        $tenant = Tenant::factory()->create();

        $this->post('/hx-control/bots/templates', [
            'key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'bot' => 'support',
            'content' => 'Platform wording.',
        ]);

        // The whole point: this is what their customers now see.
        $this->assertSame(
            'Platform wording.',
            ResponseTemplate::resolve($tenant->id, 'SUPPORT_MENU', 'en'),
        );
    }

    public function test_a_reseller_who_wrote_their_own_keeps_it(): void
    {
        $tenant = Tenant::factory()->create();

        ResponseTemplate::create([
            'tenant_id' => $tenant->id,
            'template_key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'content' => 'Their own careful wording.',
        ]);

        $this->post('/hx-control/bots/templates', [
            'key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'bot' => 'support',
            'content' => 'Platform wording.',
        ]);

        // Overwriting this would be the platform silently rewriting text a
        // reseller chose for their own customers.
        $this->assertSame(
            'Their own careful wording.',
            ResponseTemplate::resolve($tenant->id, 'SUPPORT_MENU', 'en'),
        );
    }

    public function test_clearing_a_default_falls_back_to_the_built_in_wording(): void
    {
        ResponseTemplate::create([
            'tenant_id' => null,
            'template_key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'content' => 'Temporary wording.',
            'is_default' => true,
        ]);

        $this->post('/hx-control/bots/templates', [
            'key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'bot' => 'support',
            'content' => '',
        ])->assertRedirect();

        // Deleted rather than blanked: an empty string would be a real override
        // that sends nothing.
        $this->assertDatabaseMissing('response_templates', [
            'tenant_id' => null,
            'template_key' => 'SUPPORT_MENU',
            'lang' => 'en',
        ]);

        $this->assertDatabaseHas('activity_log', ['action' => 'templates.clear']);
    }

    public function test_saving_records_how_many_resellers_it_reaches(): void
    {
        Tenant::factory()->count(4)->create();
        $overrider = Tenant::factory()->create();

        ResponseTemplate::create([
            'tenant_id' => $overrider->id,
            'template_key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'content' => 'Mine.',
        ]);

        $this->post('/hx-control/bots/templates', [
            'key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'bot' => 'support',
            'content' => 'Platform wording.',
        ]);

        $entry = ActivityLog::where('action', 'templates.save')->first();

        // Five tenants, one of whom has their own — four will feel this.
        $this->assertSame(4, $entry->details['affects']);
    }

    public function test_an_unknown_template_key_is_refused(): void
    {
        $this->post('/hx-control/bots/templates', [
            'key' => 'NOT_A_REAL_KEY',
            'lang' => 'en',
            'bot' => 'support',
            'content' => 'Nothing sends this.',
        ])->assertStatus(422);

        $this->assertDatabaseCount('response_templates', 0);
    }

    public function test_templates_can_be_written_per_language(): void
    {
        foreach (['en', 'sw'] as $lang) {
            $this->post('/hx-control/bots/templates', [
                'key' => 'SUPPORT_MENU',
                'lang' => $lang,
                'bot' => 'support',
                'content' => "Wording in {$lang}.",
            ]);
        }

        $this->assertDatabaseCount('response_templates', 2);

        $tenant = Tenant::factory()->create();

        $this->assertSame(
            'Wording in sw.',
            ResponseTemplate::resolve($tenant->id, 'SUPPORT_MENU', 'sw'),
        );
    }

    public function test_a_support_admin_can_look_but_not_rewrite_the_platform_copy(): void
    {
        $support = Superadmin::factory()->support()->create();

        $this->actingAs($support, 'superadmin');

        $this->get('/hx-control/bots/support/templates')->assertOk();

        $this->post('/hx-control/bots/templates', [
            'key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'bot' => 'support',
            'content' => 'Not allowed.',
        ])->assertForbidden();

        $this->assertDatabaseCount('response_templates', 0);
    }

    public function test_the_defaults_tab_shows_what_a_new_reseller_inherits(): void
    {
        $this->get('/hx-control/bots/order/defaults')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('defaults.commands')
                ->has('defaults.spam')
                ->has('defaults.shop'),
        );
    }

    public function test_a_reseller_cannot_reach_the_bot_screens(): void
    {
        Auth::guard('superadmin')->logout();

        $this->actingAs(Tenant::factory()->create(), 'tenant');

        $this->get('/hx-control/bots/order')->assertRedirect(route('admin.login'));
    }
}
