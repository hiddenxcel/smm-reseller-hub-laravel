<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Bots\Order\OrderBotHandler;
use App\Services\Payments\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * A reseller choosing the name customers see for a gateway in the bot.
 */
class GatewayDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '255700000001';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::factory()->create();
        BotCustomer::factory()->for($this->tenant)->create(['phone' => self::CUSTOMER, 'balance' => '0.00']);
    }

    private function connect(string $gateway, array $attributes = []): TenantPaymentGateway
    {
        return TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => $gateway,
            'api_key_enc' => 'api-key',
            'webhook_secret_enc' => 'shhh',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function rename(string $gateway, ?string $name)
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->post(route('order-bot.gateways.name', $gateway), ['name' => $name]);
    }

    /** The titles the bot shows in its payment menu. */
    private function menuTitles(): array
    {
        $messenger = new FakeBotMessenger;
        $handler = new OrderBotHandler($this->tenant, $messenger);

        foreach (['hi', 'main:topup', '10'] as $text) {
            $handler->handle(self::CUSTOMER, $text);
        }

        $last = end($messenger->sent);

        return array_column($last['rows'] ?? [], 'title', 'id');
    }

    public function test_the_bot_shows_the_name_the_reseller_chose(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus');

        $this->rename('snippe', 'Pay with M-Pesa')->assertSessionHas('success');

        $titles = $this->menuTitles();

        $this->assertSame('Pay with M-Pesa', $titles['pay:snippe']);
        // The one she did not rename keeps the provider's own name.
        $this->assertSame(mb_substr(Gateway::label('cryptomus'), 0, 24), $titles['pay:cryptomus']);
    }

    public function test_a_gateway_with_no_name_keeps_the_providers(): void
    {
        $this->connect('snippe');
        $this->connect('cryptomus');

        $titles = $this->menuTitles();

        $this->assertSame(mb_substr(Gateway::label('snippe'), 0, 24), $titles['pay:snippe']);
    }

    public function test_a_blank_name_puts_the_default_back(): void
    {
        $this->connect('snippe', ['display_name' => 'Old name']);

        $this->rename('snippe', '   ')->assertSessionHas('success');

        $this->assertNull(TenantPaymentGateway::withoutTenantScope()->where('gateway', 'snippe')->value('display_name'));
    }

    public function test_a_name_longer_than_a_list_row_can_show_is_refused(): void
    {
        $this->connect('snippe');

        $this->rename('snippe', str_repeat('a', 25))->assertSessionHasErrors('name');

        $this->assertNull(TenantPaymentGateway::withoutTenantScope()->where('gateway', 'snippe')->value('display_name'));
    }

    public function test_markup_in_a_name_is_stripped(): void
    {
        $this->connect('snippe');

        $this->rename('snippe', '<b>M-Pesa</b>');

        $this->assertSame('M-Pesa', TenantPaymentGateway::withoutTenantScope()->where('gateway', 'snippe')->value('display_name'));
    }

    public function test_a_gateway_that_is_not_connected_cannot_be_named(): void
    {
        $this->rename('snippe', 'Nope')->assertNotFound();
    }

    public function test_another_resellers_gateway_is_untouched(): void
    {
        $other = Tenant::factory()->create();
        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $other->id, 'gateway' => 'snippe', 'api_key_enc' => 'k', 'status' => 'active',
        ]);
        $this->connect('snippe');

        $this->rename('snippe', 'Mine');

        $this->assertNull(TenantPaymentGateway::withoutTenantScope()->where('tenant_id', $other->id)->value('display_name'));
    }

    public function test_the_page_hands_the_name_to_the_form(): void
    {
        $this->connect('cryptomus', ['display_name' => 'Pay in USDT']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('order-bot.gateways'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('gateways', fn ($gateways) => collect($gateways)
                    ->contains(fn ($gateway) => $gateway['code'] === 'cryptomus' && $gateway['displayName'] === 'Pay in USDT')));
    }

    public function test_family_markets_can_be_named_one_by_one(): void
    {
        $this->connect('snippe');
        $this->connect('snippe_ke', ['display_name' => 'M-Pesa Kenya']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('order-bot.gateways'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('families', function ($families) {
                    $snippe = collect($families)->firstWhere('family', 'snippe');
                    $ke = collect($snippe['markets'])->firstWhere('code', 'snippe_ke');
                    $ug = collect($snippe['markets'])->firstWhere('code', 'snippe_ug');

                    return $ke['connected'] === true && $ke['displayName'] === 'M-Pesa Kenya' && $ug['connected'] === false;
                }));
    }

    public function test_saving_a_familys_keys_does_not_wipe_the_names(): void
    {
        $this->connect('snippe_ke', ['display_name' => 'M-Pesa Kenya']);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('order-bot.gateways.family', 'snippe'), [
                'credentials' => ['api_key' => 'new', 'webhook_secret' => 'new'],
                'markets' => ['snippe_ke'],
            ])->assertSessionHas('success');

        $this->assertSame('M-Pesa Kenya', TenantPaymentGateway::withoutTenantScope()->where('gateway', 'snippe_ke')->value('display_name'));
    }
}
