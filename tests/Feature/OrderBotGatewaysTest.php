<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The gateways screen.
 *
 * These credentials are a reseller's own merchant account — the thing their
 * customers' money passes through — so the risks are the obvious ones: a key
 * reaching the browser, a key being wiped by a save that never meant to touch
 * it, and one reseller reading or editing another's.
 */
class OrderBotGatewaysTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function connect(string $gateway = 'snippe', array $attributes = []): TenantPaymentGateway
    {
        return TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => $gateway,
            'api_key_enc' => 'key-1',
            'webhook_secret_enc' => 'secret-1',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    public function test_it_lists_every_configured_gateway(): void
    {
        $this->actingAs($this->tenant)
            ->get(route('order-bot.gateways'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('OrderBot/Gateways')
                ->has('gateways', count(config('gateways'))),
            );
    }

    /**
     * A gateway with no client cannot take a payment however complete its keys
     * look, and the page is what has to say so.
     *
     * Both examples come from config: hard-coding a name means this quietly
     * stops testing the distinction the day that gateway changes side.
     */
    public function test_it_reports_which_gateways_are_wired_up(): void
    {
        $codes = collect(array_keys(config('gateways')));
        $wired = $codes->first(fn (string $code) => Gateway::isReady($code));
        $unwired = $codes->first(fn (string $code) => ! Gateway::isReady($code));

        $response = $this->actingAs($this->tenant)->get(route('order-bot.gateways'));

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($wired, $unwired) {
            $gateways = collect($page->toArray()['props']['gateways']);

            $this->assertTrue($gateways->firstWhere('code', $wired)['ready']);

            if ($unwired !== null) {
                $this->assertFalse($gateways->firstWhere('code', $unwired)['ready']);
            }
        });
    }

    public function test_a_stored_key_never_reaches_the_browser(): void
    {
        $this->connect('snippe', ['api_key_enc' => 'super-secret-key']);

        $response = $this->actingAs($this->tenant)->get(route('order-bot.gateways'));

        $response->assertOk();
        $response->assertDontSee('super-secret-key');
    }

    /** The form needs to know a value exists without being shown it. */
    public function test_it_reports_which_fields_have_something_saved(): void
    {
        $this->connect('snippe', ['api_key_enc' => 'k', 'webhook_secret_enc' => null]);

        $response = $this->actingAs($this->tenant)->get(route('order-bot.gateways'));

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $snippe = collect($page->toArray()['props']['gateways'])
                ->firstWhere('code', 'snippe');

            $this->assertTrue($snippe['connected']);
            $this->assertTrue(collect($snippe['fields'])->firstWhere('name', 'api_key')['saved']);
            $this->assertFalse(collect($snippe['fields'])->firstWhere('name', 'webhook_secret')['saved']);
        });
    }

    public function test_it_does_not_show_another_tenants_connection(): void
    {
        $other = Tenant::factory()->create();
        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $other->id,
            'gateway' => 'snippe',
            'api_key_enc' => 'theirs',
            'webhook_secret_enc' => 'theirs',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->tenant)->get(route('order-bot.gateways'));

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $snippe = collect($page->toArray()['props']['gateways'])
                ->firstWhere('code', 'snippe');

            $this->assertFalse($snippe['connected']);
        });
    }

    public function test_it_saves_credentials_encrypted(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.gateways.store'), [
                'gateway' => 'snippe',
                'credentials' => ['api_key' => 'my-key', 'webhook_secret' => 'my-secret'],
            ])
            ->assertRedirect();

        $row = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('gateway', 'snippe')
            ->firstOrFail();

        // Readable through the cast, unreadable in the column itself.
        $this->assertSame('my-key', $row->api_key_enc);
        $this->assertNotSame('my-key', $row->getRawOriginal('api_key_enc'));
    }

    /**
     * The form never sends a stored secret back, so a blank field means "keep
     * it" — treating it as a deletion would wipe a working key.
     */
    public function test_a_blank_field_keeps_the_stored_value(): void
    {
        $this->connect('snippe', ['api_key_enc' => 'original-key']);

        $this->actingAs($this->tenant)
            ->post(route('order-bot.gateways.store'), [
                'gateway' => 'snippe',
                'credentials' => ['api_key' => '', 'webhook_secret' => 'new-secret'],
            ])
            ->assertRedirect();

        $row = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('gateway', 'snippe')
            ->firstOrFail();

        $this->assertSame('original-key', $row->api_key_enc);
        $this->assertSame('new-secret', $row->webhook_secret_enc);
    }

    /** Blank with nothing stored would connect a gateway that cannot work. */
    public function test_a_blank_field_is_refused_when_nothing_is_stored(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.gateways.store'), [
                'gateway' => 'snippe',
                'credentials' => ['api_key' => '', 'webhook_secret' => 'secret'],
            ])
            ->assertSessionHasErrors('credentials.api_key');

        $this->assertSame(0, TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->count());
    }

    public function test_it_refuses_an_unknown_gateway(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.gateways.store'), [
                'gateway' => 'not-a-gateway',
                'credentials' => ['api_key' => 'k'],
            ])
            ->assertSessionHasErrors('gateway');
    }

    /** Keys survive a pause, so resuming does not mean finding them again. */
    public function test_toggling_pauses_and_resumes_without_losing_keys(): void
    {
        $this->connect('snippe');

        $this->actingAs($this->tenant)
            ->post(route('order-bot.gateways.toggle', 'snippe'))
            ->assertRedirect();

        $row = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('gateway', 'snippe')
            ->firstOrFail();

        $this->assertSame('inactive', $row->status);
        $this->assertSame('key-1', $row->api_key_enc);

        $this->actingAs($this->tenant)
            ->post(route('order-bot.gateways.toggle', 'snippe'))
            ->assertRedirect();

        $this->assertSame('active', $row->fresh()->status);
    }

    public function test_it_removes_a_gateway(): void
    {
        $this->connect('snippe');

        $this->actingAs($this->tenant)
            ->delete(route('order-bot.gateways.destroy', 'snippe'))
            ->assertRedirect();

        $this->assertSame(0, TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->count());
    }

    public function test_it_cannot_remove_another_tenants_gateway(): void
    {
        $other = Tenant::factory()->create();
        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $other->id,
            'gateway' => 'snippe',
            'api_key_enc' => 'theirs',
            'webhook_secret_enc' => 'theirs',
            'status' => 'active',
        ]);

        $this->actingAs($this->tenant)
            ->delete(route('order-bot.gateways.destroy', 'snippe'))
            ->assertRedirect();

        // Their row is untouched: the delete was scoped to the actor.
        $this->assertDatabaseHas('tenant_payment_gateways', [
            'tenant_id' => $other->id,
            'gateway' => 'snippe',
        ]);
    }

    public function test_it_cannot_toggle_another_tenants_gateway(): void
    {
        $other = Tenant::factory()->create();
        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $other->id,
            'gateway' => 'snippe',
            'api_key_enc' => 'theirs',
            'webhook_secret_enc' => 'theirs',
            'status' => 'active',
        ]);

        $this->actingAs($this->tenant)
            ->post(route('order-bot.gateways.toggle', 'snippe'))
            ->assertNotFound();

        $this->assertDatabaseHas('tenant_payment_gateways', [
            'tenant_id' => $other->id,
            'status' => 'active',
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->get(route('order-bot.gateways'))->assertRedirect(route('login'));
    }

    /** `/order-bot/gateways` must not be swallowed by the `{tab}` route. */
    public function test_the_gateways_url_is_not_read_as_a_tab(): void
    {
        $this->actingAs($this->tenant)
            ->get('/order-bot/gateways')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('OrderBot/Gateways'));
    }
}
