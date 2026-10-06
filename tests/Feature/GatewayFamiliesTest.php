<?php

namespace Tests\Feature;

use App\Models\PlatformGatewayCredential;
use App\Models\Superadmin;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Billing\PlatformGateways;
use App\Services\Payments\FimipayClient;
use App\Services\Payments\GatewayFamilies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * FimiPay and Snippe are each one account sold in several markets: the keys
 * are entered once and each country is only a switch — for the platform and
 * for a reseller.
 */
class GatewayFamiliesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite inherits whatever .env holds; these tests are about the
        // database path.
        foreach (['fimipay', 'snippe'] as $family) {
            foreach (GatewayFamilies::codes($family) as $code) {
                config(["services.billing.{$code}.api_key" => null]);
            }
        }

        PlatformGatewayCredential::forget();
    }

    // ---- the platform's own account --------------------------------------

    private function owner(): Superadmin
    {
        return Superadmin::factory()->create(['role' => 'owner']);
    }

    public function test_one_save_gives_every_market_the_same_keys(): void
    {
        $this->actingAs($this->owner(), 'superadmin')
            ->post(route('admin.settings.family.save', 'fimipay'), [
                'api_key' => 'sk_live_ABCD',
                'webhook_secret' => 'whsec_1',
                'markets' => ['fimipay_gh', 'fimipay_cm'],
            ])
            ->assertRedirect();

        PlatformGatewayCredential::forget();

        foreach (FimipayClient::codes() as $code) {
            $row = PlatformGatewayCredential::all()->get($code);
            $this->assertSame('sk_live_ABCD', $row->api_key_enc, $code);
            $this->assertSame('whsec_1', $row->webhook_secret_enc, $code);
        }
    }

    public function test_only_the_switched_on_markets_are_offered(): void
    {
        $this->actingAs($this->owner(), 'superadmin')
            ->post(route('admin.settings.family.save', 'fimipay'), [
                'api_key' => 'sk_live_ABCD',
                'markets' => ['fimipay_gh'],
            ]);

        PlatformGatewayCredential::forget();

        $this->assertTrue(PlatformGateways::isConfigured('fimipay_gh'));
        $this->assertFalse(PlatformGateways::isConfigured('fimipay_ng'));
        $this->assertFalse(PlatformGateways::isConfigured('fimipay_usd'));
    }

    public function test_a_blank_key_keeps_the_stored_one_and_only_changes_the_switches(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'superadmin')->post(route('admin.settings.family.save', 'fimipay'), [
            'api_key' => 'sk_live_ABCD',
            'markets' => ['fimipay_gh'],
        ]);

        $this->actingAs($owner, 'superadmin')->post(route('admin.settings.family.save', 'fimipay'), [
            'api_key' => '',
            'markets' => ['fimipay_gh', 'fimipay_za'],
        ])->assertSessionHasNoErrors();

        PlatformGatewayCredential::forget();

        $this->assertTrue(PlatformGateways::isConfigured('fimipay_za'));
        $this->assertSame('sk_live_ABCD', PlatformGateways::keys('fimipay_za')['api_key']);
    }

    public function test_a_market_borrows_the_key_saved_against_another_one(): void
    {
        // How it was before: only Nigeria had a key. Turning Ghana on must not
        // need the key typed again.
        PlatformGatewayCredential::create([
            'gateway' => 'fimipay_ng', 'api_key_enc' => 'sk_live_NG01', 'enabled' => true,
        ]);
        PlatformGatewayCredential::create(['gateway' => 'fimipay_gh', 'enabled' => true]);
        PlatformGatewayCredential::forget();

        $this->assertTrue(PlatformGateways::isConfigured('fimipay_gh'));
        $this->assertSame('sk_live_NG01', PlatformGateways::keys('fimipay_gh')['api_key']);
    }

    public function test_saving_without_any_key_is_refused(): void
    {
        $this->actingAs($this->owner(), 'superadmin')
            ->post(route('admin.settings.family.save', 'fimipay'), ['api_key' => '', 'markets' => ['fimipay_gh']])
            ->assertSessionHasErrors('api_key');
    }

    public function test_only_an_owner_may_save_the_platform_keys(): void
    {
        $support = Superadmin::factory()->create(['role' => 'support']);

        $this->actingAs($support, 'superadmin')
            ->post(route('admin.settings.family.save', 'fimipay'), ['api_key' => 'sk_live_ABCD', 'markets' => []])
            ->assertForbidden();

        $this->assertDatabaseCount('platform_gateway_credentials', 0);
    }

    public function test_the_console_shows_fimipay_as_one_card_without_the_key(): void
    {
        $this->actingAs($this->owner(), 'superadmin')->post(route('admin.settings.family.save', 'fimipay'), [
            'api_key' => 'sk_live_SECRET99',
            'markets' => ['fimipay_gh'],
        ]);
        PlatformGatewayCredential::forget();

        $response = $this->actingAs($this->owner(), 'superadmin')->get(route('admin.settings'));

        $response->assertOk()->assertDontSee('sk_live_SECRET99');
        $response->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];

            $this->assertSame([], collect($props['gateways'])->filter(
                fn (array $gateway) => GatewayFamilies::familyOf($gateway['code']) !== null,
            )->values()->all());
            $this->assertTrue(collect($props['families'])->firstWhere('family', 'fimipay')['keySaved']);
            $this->assertSame('…ET99', collect($props['families'])->firstWhere('family', 'fimipay')['hint']);
            $this->assertSame(['fimipay_gh'], collect(collect($props['families'])->firstWhere('family', 'fimipay')['markets'])->where('on', true)->pluck('code')->all());
        });
    }

    // ---- a reseller's own account ----------------------------------------

    public function test_a_reseller_enters_the_keys_once_for_every_market(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($tenant)
            ->post(route('order-bot.gateways.family', 'fimipay'), [
                'credentials' => ['api_key' => 'sk_live_RES1', 'webhook_secret' => ''],
                'markets' => ['fimipay_ng', 'fimipay_usd'],
            ])
            ->assertRedirect();

        $rows = TenantPaymentGateway::withoutTenantScope()->where('tenant_id', $tenant->id)->get()->keyBy('gateway');

        $this->assertCount(5, $rows);
        $this->assertSame('sk_live_RES1', $rows['fimipay_gh']->api_key_enc);
        $this->assertSame('active', $rows['fimipay_ng']->status);
        $this->assertSame('active', $rows['fimipay_usd']->status);
        $this->assertSame('inactive', $rows['fimipay_gh']->status);
    }

    public function test_a_reseller_can_change_the_markets_without_retyping_the_key(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($tenant)->post(route('order-bot.gateways.family', 'fimipay'), [
            'credentials' => ['api_key' => 'sk_live_RES1'],
            'markets' => ['fimipay_ng'],
        ]);

        $this->actingAs($tenant)->post(route('order-bot.gateways.family', 'fimipay'), [
            'credentials' => ['api_key' => ''],
            'markets' => ['fimipay_ng', 'fimipay_gh'],
        ])->assertSessionHasNoErrors();

        $gh = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->where('gateway', 'fimipay_gh')->first();

        $this->assertSame('active', $gh->status);
        $this->assertSame('sk_live_RES1', $gh->api_key_enc);
    }

    public function test_the_first_save_needs_a_key(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($tenant)
            ->post(route('order-bot.gateways.family', 'fimipay'), ['credentials' => ['api_key' => ''], 'markets' => ['fimipay_ng']])
            ->assertSessionHasErrors('credentials.api_key');

        $this->assertSame(0, TenantPaymentGateway::withoutTenantScope()->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_reseller_cannot_switch_on_a_market_that_does_not_exist(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($tenant)
            ->post(route('order-bot.gateways.family', 'fimipay'), [
                'credentials' => ['api_key' => 'sk_live_RES1'],
                'markets' => ['fimipay_mars'],
            ])
            ->assertSessionHasErrors('markets.0');
    }

    public function test_the_gateways_page_shows_fimipay_as_one_card_without_the_key(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs($tenant)->post(route('order-bot.gateways.family', 'fimipay'), [
            'credentials' => ['api_key' => 'sk_live_HIDDEN77'],
            'markets' => ['fimipay_cm'],
        ]);

        $response = $this->actingAs($tenant)->get(route('order-bot.gateways'));

        $response->assertOk()->assertDontSee('sk_live_HIDDEN77');
        $response->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];

            $this->assertSame([], collect($props['gateways'])->filter(
                fn (array $gateway) => GatewayFamilies::familyOf($gateway['code']) !== null,
            )->values()->all());
            $this->assertTrue(collect($props['families'])->firstWhere('family', 'fimipay')['keySaved']);
            $this->assertSame(['fimipay_cm'], collect(collect($props['families'])->firstWhere('family', 'fimipay')['markets'])->where('on', true)->pluck('code')->all());
        });
    }

    public function test_one_reseller_cannot_touch_anothers_fimipay(): void
    {
        $mine = Tenant::factory()->create();
        $theirs = Tenant::factory()->create();

        $this->actingAs($theirs)->post(route('order-bot.gateways.family', 'fimipay'), [
            'credentials' => ['api_key' => 'sk_live_THEIRS'],
            'markets' => ['fimipay_ng'],
        ]);

        $this->actingAs($mine)->get(route('order-bot.gateways'))->assertInertia(
            fn (AssertableInertia $page) => $this->assertFalse(collect($page->toArray()['props']['families'])->firstWhere('family', 'fimipay')['keySaved']),
        );
    }

    // ---- Snippe: the same shape, but its webhook secret is required ------

    public function test_snippe_is_one_card_with_three_markets(): void
    {
        $this->assertSame(['snippe', 'snippe_ke', 'snippe_ug'], GatewayFamilies::codes('snippe'));
    }

    public function test_one_snippe_save_gives_every_market_the_same_keys(): void
    {
        $this->actingAs($this->owner(), 'superadmin')
            ->post(route('admin.settings.family.save', 'snippe'), [
                'api_key' => 'snp_live_ABCD',
                'webhook_secret' => 'whsec_snp',
                'markets' => ['snippe', 'snippe_ke'],
            ])
            ->assertRedirect();

        PlatformGatewayCredential::forget();

        foreach (GatewayFamilies::codes('snippe') as $code) {
            $this->assertSame('snp_live_ABCD', PlatformGatewayCredential::all()->get($code)->api_key_enc, $code);
        }

        $this->assertTrue(PlatformGateways::isConfigured('snippe_ke'));
        $this->assertFalse(PlatformGateways::isConfigured('snippe_ug'));
        $this->assertSame('whsec_snp', PlatformGateways::keys('snippe_ke')['webhook_secret']);
    }

    public function test_snippe_needs_its_webhook_secret_too(): void
    {
        $this->actingAs($this->owner(), 'superadmin')
            ->post(route('admin.settings.family.save', 'snippe'), [
                'api_key' => 'snp_live_ABCD',
                'markets' => ['snippe'],
            ])
            ->assertSessionHasErrors('webhook_secret');

        $this->assertDatabaseCount('platform_gateway_credentials', 0);
    }

    public function test_an_unknown_family_is_not_found(): void
    {
        $this->actingAs($this->owner(), 'superadmin')
            ->post(route('admin.settings.family.save', 'stripe'), ['api_key' => 'x', 'markets' => []])
            ->assertNotFound();
    }

    public function test_a_reseller_enters_snippe_once_for_every_market(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($tenant)
            ->post(route('order-bot.gateways.family', 'snippe'), [
                'credentials' => ['api_key' => 'snp_RES', 'webhook_secret' => 'whsec_RES'],
                'markets' => ['snippe', 'snippe_ug'],
            ])
            ->assertRedirect();

        $rows = TenantPaymentGateway::withoutTenantScope()->where('tenant_id', $tenant->id)->get()->keyBy('gateway');

        $this->assertCount(3, $rows);
        $this->assertSame('whsec_RES', $rows['snippe_ke']->webhook_secret_enc);
        $this->assertSame('active', $rows['snippe']->status);
        $this->assertSame('inactive', $rows['snippe_ke']->status);
        $this->assertSame('active', $rows['snippe_ug']->status);
    }

    public function test_a_reseller_must_give_snippe_its_webhook_secret(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($tenant)
            ->post(route('order-bot.gateways.family', 'snippe'), [
                'credentials' => ['api_key' => 'snp_RES', 'webhook_secret' => ''],
                'markets' => ['snippe'],
            ])
            ->assertSessionHasErrors('credentials.webhook_secret');
    }

    public function test_a_reseller_family_must_be_a_known_one(): void
    {
        $this->actingAs(Tenant::factory()->create())
            ->post(route('order-bot.gateways.family', 'stripe'), ['credentials' => ['api_key' => 'x'], 'markets' => []])
            ->assertNotFound();
    }
}
