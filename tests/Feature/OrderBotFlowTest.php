<?php

namespace Tests\Feature;

use App\Jobs\SubmitOrderToPanel;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotService;
use App\Models\Tenant;
use App\Services\Bots\BotSettings;
use App\Services\Bots\Order\OrderBotHandler;
use App\Services\Bots\Order\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * The selling flow, end to end: a customer says hi and walks out with an
 * order placed and their wallet debited.
 */
class OrderBotFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '255700000001';

    private Tenant $tenant;

    private FakeBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::factory()->create(['business_name' => 'Kuza Panel']);
        $this->messenger = new FakeBotMessenger;
    }

    private function bot(): OrderBotHandler
    {
        return new OrderBotHandler($this->tenant, $this->messenger);
    }

    private function send(string $text): void
    {
        $this->bot()->handle(self::CUSTOMER, $text);
    }

    private function state(): ?string
    {
        return BotConversation::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_phone', self::CUSTOMER)
            ->value('state');
    }

    private function context(): array
    {
        return BotConversation::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_phone', self::CUSTOMER)
            ->value('context') ?? [];
    }

    private function service(array $attributes = []): BotService
    {
        return BotService::factory()->for($this->tenant)->create($attributes);
    }

    private function customerWith(string $balance): BotCustomer
    {
        return BotCustomer::factory()->for($this->tenant)->create([
            'phone' => self::CUSTOMER,
            'balance' => $balance,
        ]);
    }

    // ---- entry -----------------------------------------------------------

    public function test_saying_hi_opens_the_main_menu(): void
    {
        $this->send('hi');

        $this->assertSame(OrderState::MainMenu->value, $this->state());
        $this->assertSame('list', $this->messenger->sent[0]['type']);
        $this->assertStringContainsString('Kuza Panel', $this->messenger->sent[0]['body']);
    }

    public function test_it_creates_the_customer_on_first_contact(): void
    {
        $this->send('hi');

        $this->assertDatabaseHas('bot_customers', [
            'tenant_id' => $this->tenant->id,
            'phone' => self::CUSTOMER,
        ]);
    }

    public function test_a_reset_word_returns_to_the_menu_from_anywhere(): void
    {
        $this->service();
        $this->send('hi');
        $this->send('main:new_order');
        $this->assertSame(OrderState::SelectPlatform->value, $this->state());

        $this->send('menu');

        $this->assertSame(OrderState::MainMenu->value, $this->state());
    }

    // ---- the order flow ---------------------------------------------------

    public function test_a_customer_can_order_from_hi_to_placed(): void
    {
        $this->service(['name' => 'IG Followers', 'my_price' => '2.0000']);
        $customer = $this->customerWith('10.00');

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');

        $serviceId = array_key_first($this->context()['services']);
        $this->send("svc_{$serviceId}");
        $this->send('qty_500');
        $this->send('https://instagram.com/someone');
        $this->send('confirm_yes');

        // 500 units at 2.00 per 1000 = 1.00
        $this->assertDatabaseHas('bot_orders', [
            'tenant_id' => $this->tenant->id,
            'customer_phone' => self::CUSTOMER,
            'service_name' => 'IG Followers',
            'quantity' => 500,
            'amount' => '1.00',
            'payment_status' => 'paid',
            'paid_from' => 'wallet',
        ]);

        $this->assertSame('9.00', (string) $customer->fresh()->balance);
        $this->assertNull($this->state(), 'the conversation should be finished');
    }

    public function test_it_queues_the_order_for_the_panel_rather_than_calling_it_inline(): void
    {
        $this->service();
        $this->customerWith('10.00');

        $this->orderThrough('qty_500', 'https://instagram.com/someone');

        Queue::assertPushed(SubmitOrderToPanel::class);
    }

    public function test_the_price_is_charged_per_thousand_units(): void
    {
        $this->service(['my_price' => '3.5000']);
        $customer = $this->customerWith('10.00');

        $this->orderThrough('qty_2000', 'https://instagram.com/someone');

        // 2000 at 3.50/1000 = 7.00
        $this->assertSame('3.00', (string) $customer->fresh()->balance);
    }

    public function test_the_category_step_is_skipped_when_there_is_only_one(): void
    {
        $this->service(['category' => 'Followers']);

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');

        $this->assertSame(OrderState::SelectService->value, $this->state());
    }

    public function test_the_category_step_appears_when_there_are_several(): void
    {
        $this->service(['category' => 'Followers', 'name' => 'IG Followers']);
        $this->service(['category' => 'Likes', 'name' => 'IG Likes']);

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');

        $this->assertSame(OrderState::SelectCategory->value, $this->state());
    }

    public function test_choosing_a_category_narrows_the_services(): void
    {
        $this->service(['category' => 'Followers', 'name' => 'IG Followers']);
        $this->service(['category' => 'Likes', 'name' => 'IG Likes']);

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');
        $this->send('cat_Likes');

        $names = array_column($this->context()['services'], 'name');
        $this->assertSame(['IG Likes'], $names);
    }

    public function test_hidden_services_are_not_offered(): void
    {
        $this->service(['name' => 'Live One']);
        $this->service(['name' => 'Retired One'])->update(['status' => BotService::HIDDEN]);

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');

        $names = array_column($this->context()['services'], 'name');
        $this->assertSame(['Live One'], $names);
    }

    /**
     * Paused is not hidden: the customer still sees the service, so they know
     * it exists, and is told no only if they pick it. A panel having a bad day
     * should not make a reseller's catalogue appear to shrink.
     */
    public function test_a_paused_service_is_listed_but_cannot_be_ordered(): void
    {
        $this->service(['name' => 'Live One']);
        $paused = $this->service(['name' => 'Paused One']);
        $paused->update(['status' => BotService::PAUSED]);

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');

        $names = array_column($this->context()['services'], 'name');
        $this->assertContains('Paused One', $names);

        $this->send("svc_{$paused->id}");

        $this->assertStringContainsString(
            'unavailable',
            mb_strtolower((string) $this->messenger->lastBody()),
        );
        // Still on the service step, not moved on to quantity.
        $this->assertSame(OrderState::SelectService->value, $this->state());
    }

    public function test_a_store_with_no_services_says_so(): void
    {
        $this->send('hi');
        $this->send('main:new_order');

        $this->assertStringContainsString("isn't set up yet", (string) $this->messenger->lastBody());
        $this->assertNull($this->state());
    }

    // ---- validation -------------------------------------------------------

    public function test_a_quantity_below_the_minimum_is_refused(): void
    {
        $this->service(['min_quantity' => 500]);
        $this->customerWith('10.00');

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');
        $this->send('svc_'.array_key_first($this->context()['services']));
        $this->send('qty_100');

        $this->assertSame(OrderState::SelectQuantity->value, $this->state(), 'should not advance');
    }

    public function test_a_non_url_is_refused_as_a_link(): void
    {
        $this->service();
        $this->customerWith('10.00');

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');
        $this->send('svc_'.array_key_first($this->context()['services']));
        $this->send('qty_500');
        $this->send('not a link');

        $this->assertSame(OrderState::SendLink->value, $this->state(), 'should not advance');
        $this->assertDatabaseCount('bot_orders', 0);
    }

    public function test_declining_the_confirmation_places_nothing(): void
    {
        $this->service();
        $customer = $this->customerWith('10.00');

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');
        $this->send('svc_'.array_key_first($this->context()['services']));
        $this->send('qty_500');
        $this->send('https://instagram.com/someone');
        $this->send('confirm_no');

        $this->assertDatabaseCount('bot_orders', 0);
        $this->assertSame('10.00', (string) $customer->fresh()->balance);
        $this->assertNull($this->state());
    }

    public function test_an_empty_wallet_is_offered_a_top_up_instead_of_an_order(): void
    {
        $this->service(['my_price' => '2.0000']);
        $customer = $this->customerWith('0.10');

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');
        $this->send('svc_'.array_key_first($this->context()['services']));
        $this->send('qty_500');
        $this->send('https://instagram.com/someone');
        $this->send('confirm_yes');

        $this->assertSame(OrderState::TopupDecision->value, $this->state());
        $this->assertDatabaseCount('bot_orders', 0);
        $this->assertSame('0.10', (string) $customer->fresh()->balance, 'nothing charged');
        // 1.00 needed, 0.10 held -> 0.90 short
        $this->assertSame('0.90', $this->context()['shortfall']);
    }

    // ---- language ---------------------------------------------------------

    public function test_a_customer_can_switch_language_and_the_menu_follows(): void
    {
        $customer = $this->customerWith('0.00');

        $this->send('hi');
        $this->send('main:settings');
        $this->send('lang:sw');

        $this->assertSame('sw', $customer->fresh()->lang);
        // The reopened menu should be in the language just chosen.
        $this->assertStringContainsString('KARIBU', (string) $this->messenger->lastBody());
    }

    public function test_the_shop_default_language_is_used_for_a_new_customer(): void
    {
        BotSettings::save($this->tenant->id, 'order', ['shop' => ['lang' => 'sw']]);

        $this->send('hi');

        // A customer who has never chosen a language gets the shop's.
        $this->assertStringContainsString('KARIBU', (string) $this->messenger->lastBody());
        $this->assertSame('Fungua Menyu', $this->messenger->sent[0]['buttonText']);
    }

    // ---- isolation --------------------------------------------------------

    public function test_it_only_offers_this_tenants_services(): void
    {
        $other = Tenant::factory()->create();
        BotService::factory()->for($other)->create(['platform' => 'Instagram', 'name' => 'Someone Elses']);
        $this->service(['name' => 'Mine']);

        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');

        $names = array_column($this->context()['services'], 'name');
        $this->assertSame(['Mine'], $names);
    }

    /** Walk the flow to a placed order, for tests that only care about the end. */
    private function orderThrough(string $quantityChoice, string $link): void
    {
        $this->send('hi');
        $this->send('main:new_order');
        $this->send('plat_Instagram');
        $this->send('svc_'.array_key_first($this->context()['services']));
        $this->send($quantityChoice);
        $this->send($link);
        $this->send('confirm_yes');
    }
}
