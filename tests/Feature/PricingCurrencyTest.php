<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\BotService;
use App\Models\ServicePriceHistory;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Bots\BotSettings;
use App\Services\Payments\ExchangeRates;
use App\Services\Payments\PricingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class PricingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['currency.usd_to.KES' => 100.0]);

        $this->tenant = Tenant::factory()->create();
    }

    private function switchTo(string $currency, ?string $mode)
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->patch(route('settings.pricing-currency.update'), array_filter(['currency' => $currency, 'mode' => $mode]));
    }

    public function test_a_tenant_who_never_chose_is_on_usd(): void
    {
        $this->assertNull($this->tenant->pricing_currency);
        $this->assertSame('USD', PricingCurrency::for($this->tenant));
    }

    public function test_a_mode_must_be_chosen(): void
    {
        $this->switchTo('KES', null)->assertSessionHasErrors('mode');

        $this->assertNull($this->tenant->fresh()->pricing_currency);
    }

    public function test_a_currency_with_no_rate_is_refused(): void
    {
        $this->switchTo('XYZ', 'relabel')->assertSessionHasErrors('currency');

        $this->assertNull($this->tenant->fresh()->pricing_currency);
    }

    public function test_relabelling_changes_the_label_and_no_number(): void
    {
        $service = BotService::factory()->for($this->tenant)->create(['my_price' => '2000.0000', 'cost_price' => '1500.0000']);
        $customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => '50.00']);

        $this->switchTo('KES', 'relabel')->assertSessionHasNoErrors();

        $this->assertSame('KES', $this->tenant->fresh()->pricing_currency);
        $this->assertSame('KES', Arr::get(BotSettings::for($this->tenant->id, 'order'), 'shop.currency'));
        $this->assertSame('2000.0000', (string) $service->fresh()->my_price);
        $this->assertSame('50.00', (string) $customer->fresh()->balance);
        $this->assertSame(0, ServicePriceHistory::withoutTenantScope()->count());
    }

    public function test_converting_moves_prices_wallets_orders_and_payments_together(): void
    {
        $service = BotService::factory()->for($this->tenant)->create(['my_price' => '2.0000', 'cost_price' => '1.5000']);
        $customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => '10.00', 'total_spent' => '4.00']);
        $order = BotOrder::factory()->for($this->tenant)->create(['amount' => '3.00', 'charge' => '2.0000', 'refunded_amount' => '1.00']);

        $this->switchTo('KES', 'convert')->assertSessionHasNoErrors();

        $this->assertSame('KES', $this->tenant->fresh()->pricing_currency);
        $this->assertSame('200.0000', (string) $service->fresh()->my_price);
        $this->assertSame('150.0000', (string) $service->fresh()->cost_price);
        $this->assertSame('1000.00', (string) $customer->fresh()->balance);
        $this->assertSame('400.00', (string) $customer->fresh()->total_spent);
        $this->assertSame('300.00', (string) $order->fresh()->amount);
        $this->assertSame('100.00', (string) $order->fresh()->refunded_amount);

        $history = ServicePriceHistory::withoutTenantScope()->where('service_id', $service->id)->first();
        $this->assertSame(ServicePriceHistory::CURRENCY_SWITCH, $history->reason);
    }

    public function test_another_shops_money_is_not_touched(): void
    {
        $other = Tenant::factory()->create();
        $theirs = BotCustomer::factory()->for($other)->create(['balance' => '10.00']);

        $this->switchTo('KES', 'convert');

        $this->assertSame('10.00', (string) $theirs->fresh()->balance);
        $this->assertNull($other->fresh()->pricing_currency);
    }

    public function test_a_payment_in_progress_blocks_a_conversion(): void
    {
        BotCustomer::factory()->for($this->tenant)->create(['balance' => '10.00']);
        BotPayment::factory()->for($this->tenant)->create(['status' => 'pending', 'amount' => '5.00']);

        $this->switchTo('KES', 'convert')->assertSessionHas('error');

        $this->assertNull($this->tenant->fresh()->pricing_currency);
    }

    public function test_a_panels_cost_is_brought_into_the_shops_currency(): void
    {
        $this->tenant->update(['pricing_currency' => 'KES']);

        // A dollar panel feeding a shilling shop: 2 USD is 200 KES.
        $this->assertSame('200.0000', PricingCurrency::costFromPanel('2', 'USD', $this->tenant));
        // A shilling panel in a shilling shop is already right.
        $this->assertSame('2', PricingCurrency::costFromPanel('2', 'KES', $this->tenant));
        // A panel that does not say is taken at its word.
        $this->assertSame('2', PricingCurrency::costFromPanel('2', null, $this->tenant));
        $this->assertTrue(ExchangeRates::supports('KES'));
    }

    public function test_a_shop_that_never_chose_keeps_its_panels_numbers(): void
    {
        $this->assertNull($this->tenant->pricing_currency);

        $this->assertSame('1500', PricingCurrency::costFromPanel('1500', 'KES', $this->tenant));
    }

    public function test_the_preview_reports_what_would_change_without_changing_it(): void
    {
        BotService::factory()->for($this->tenant)->create(['my_price' => '2.0000']);
        TenantPanel::factory()->for($this->tenant)->create(['balance_currency' => 'KES']);

        $this->actingAs($this->tenant, 'tenant')
            ->getJson(route('settings.pricing-currency.preview', ['currency' => 'KES']))
            ->assertOk()
            ->assertJsonPath('panelMatches', true)
            ->assertJsonPath('services', 1)
            ->assertJsonPath('sample.0.to', '200.0000');

        $this->assertNull($this->tenant->fresh()->pricing_currency);
    }
}
