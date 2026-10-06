<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotMessage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The order bot's console.
 *
 * The two things worth real care: the status the page reports — a reseller
 * acts on "online" or "offline" and a wrong answer sends them hunting the
 * wrong problem — and that saving one group of settings does not wipe the
 * keys the form never showed.
 */
class OrderBotPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function connectNumber(string $status = 'active'): TenantWhatsApp
    {
        return TenantWhatsApp::factory()->for($this->tenant)->create([
            'bot_type' => 'order',
            'status' => $status,
            'display_number' => '+255700000001',
        ]);
    }

    /** BotMessage has no factory; the log is written by hand everywhere. */
    private function logMessage(Tenant $tenant, string $bot, string $phone, string $message): void
    {
        BotMessage::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $phone,
            'direction' => 'in',
            'message' => $message,
            'bot_type' => $bot,
        ]);
    }

    private function subscribe(): void
    {
        Subscription::factory()->for($this->tenant)->create([
            'service_key' => ServiceKey::OrderBot->value,
            'status' => 'active',
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function test_it_reports_the_bot_as_live_when_number_and_subscription_are_both_good(): void
    {
        $this->connectNumber();
        $this->subscribe();

        $this->actingAs($this->tenant)
            ->get(route('order-bot'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('OrderBot/Index')
                ->where('status.live', true)
                ->where('status.subscription', 'active')
                ->where('status.number', '+255700000001'),
            );
    }

    /**
     * A subscribed reseller with no number is offline for a different reason
     * than an unsubscribed one, and the page has to say which.
     */
    public function test_it_reports_offline_with_no_number(): void
    {
        $this->subscribe();

        $this->actingAs($this->tenant)
            ->get(route('order-bot'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('status.live', false)
                ->where('status.connected', false),
            );
    }

    public function test_it_reports_offline_without_a_subscription(): void
    {
        $this->connectNumber();

        $this->actingAs($this->tenant)
            ->get(route('order-bot'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('status.live', false)
                ->where('status.connected', true)
                ->where('status.subscription', 'inactive'),
            );
    }

    /** Only the tab being viewed is built; the others must not be sent. */
    public function test_it_sends_only_the_current_tabs_payload(): void
    {
        $this->connectNumber();

        $this->actingAs($this->tenant)
            ->get(route('order-bot', 'commands'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('commands')
                ->has('spam')
                ->missing('logs')
                ->missing('setup'),
            );
    }

    /**
     * The setup screen's whole job is naming what is missing, so each check
     * has to be reported on its own rather than rolled into one verdict.
     */
    public function test_setup_reports_each_readiness_check_separately(): void
    {
        $this->connectNumber();
        $this->subscribe();

        $this->actingAs($this->tenant)
            ->get(route('order-bot'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('setup.checks.subscription', true)
                ->where('setup.checks.whatsapp', true)
                // No panel was connected, so this one alone is false.
                ->where('setup.checks.panel', false),
            );
    }

    public function test_it_saves_language_links_and_support_mode(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.setup'), [
                'lang' => 'sw',
                'groupUrl' => 'https://chat.whatsapp.com/abc',
                'websiteUrl' => 'https://shop.example',
                'supportMode' => 'ai',
                'staff' => ['255700000002'],
            ])
            ->assertRedirect();

        $settings = BotSettings::for($this->tenant->id, 'order');

        $this->assertSame('sw', $settings['shop']['lang']);
        $this->assertSame('https://chat.whatsapp.com/abc', $settings['shop']['group_url']);
        $this->assertSame('ai', $settings['shop']['support_mode']);
        $this->assertSame(['255700000002'], $settings['staff']['numbers']);
    }

    public function test_it_rejects_a_group_link_that_is_not_a_url(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.setup'), [
                'lang' => 'en',
                'groupUrl' => 'not a url',
                'websiteUrl' => '',
                'supportMode' => 'admin',
                'staff' => [],
            ])
            ->assertSessionHasErrors('groupUrl');
    }

    /**
     * Setup and test numbers are separate posts precisely so one cannot
     * overwrite the other — this is the regression that split them.
     */
    public function test_saving_setup_leaves_test_numbers_alone(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.test-numbers'), ['testNumbers' => ['255700000007']])
            ->assertRedirect();

        $this->actingAs($this->tenant)
            ->post(route('order-bot.setup'), [
                'lang' => 'en',
                'groupUrl' => '',
                'websiteUrl' => '',
                'supportMode' => 'admin',
                'staff' => [],
            ])
            ->assertRedirect();

        $settings = BotSettings::for($this->tenant->id, 'order');

        $this->assertSame(['255700000007'], $settings['shop']['test_numbers']);
    }

    public function test_it_rejects_an_unknown_tab(): void
    {
        $this->actingAs($this->tenant)
            ->get('/order-bot/nonsense')
            ->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $this->get(route('order-bot'))->assertRedirect(route('login'));
    }

    public function test_it_saves_commands_and_anti_spam(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.commands'), [
                'commands' => ['refill' => false, 'status' => true, 'cancel' => true, 'speedup' => true],
                'spam' => ['enabled' => true, 'repeat_threshold' => 5, 'window_minutes' => 10, 'disable_minutes' => 30],
            ])
            ->assertRedirect();

        $settings = BotSettings::for($this->tenant->id, 'order');

        $this->assertFalse($settings['commands']['refill']);
        $this->assertTrue($settings['commands']['speedup']);
        $this->assertSame(5, $settings['spam']['repeat_threshold']);
    }

    public function test_it_rejects_a_repeat_threshold_that_would_block_a_second_hello(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.commands'), [
                'commands' => ['refill' => true, 'status' => true, 'cancel' => true, 'speedup' => false],
                'spam' => ['enabled' => true, 'repeat_threshold' => 1, 'window_minutes' => 5, 'disable_minutes' => 60],
            ])
            ->assertSessionHasErrors('spam.repeat_threshold');
    }

    /**
     * The settings form never shows the gateway id or the support links, so
     * saving it must leave them exactly where they were.
     */
    public function test_saving_settings_leaves_untouched_shop_keys_alone(): void
    {
        $settings = BotSettings::for($this->tenant->id, 'order');
        $settings['shop']['binance_pay_id'] = 'keep-me';
        $settings['shop']['group_url'] = 'https://chat.example/group';
        BotSettings::save($this->tenant->id, 'order', $settings);

        $this->actingAs($this->tenant)
            ->post(route('order-bot.settings'), [
                'staff' => ['255700000002'],
                'testNumbers' => [],
                'currency' => 'tzs',
                'lang' => 'sw',
                'minTopup' => 5,
                'referralPercent' => 2.5,
                'showProviderName' => false,
                'detailedStatus' => true,
            ])
            ->assertRedirect();

        $saved = BotSettings::for($this->tenant->id, 'order');

        $this->assertSame('keep-me', $saved['shop']['binance_pay_id']);
        $this->assertSame('https://chat.example/group', $saved['shop']['group_url']);
        $this->assertSame('TZS', $saved['shop']['currency']);
        $this->assertSame('sw', $saved['shop']['lang']);
        $this->assertSame(['255700000002'], $saved['staff']['numbers']);
    }

    private function saveShop(array $overrides = [])
    {
        return $this->actingAs($this->tenant)->post(route('order-bot.settings'), [
            'staff' => [],
            'testNumbers' => [],
            'currency' => 'USD',
            'lang' => 'en',
            'minTopup' => 1,
            'referralPercent' => 0,
            'showProviderName' => false,
            'detailedStatus' => true,
            ...$overrides,
        ]);
    }

    public function test_the_settings_tab_offers_every_currency_that_has_a_rate(): void
    {
        $this->actingAs($this->tenant)
            ->get(route('order-bot', 'settings'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('currencies', count(config('currency.usd_to')))
                ->where('currencies.0.code', 'USD')
                ->where('currencies.0.perUsd', 1)
                ->where('currencies.0.name', 'US dollar')
                ->where('currencies', fn ($list) => collect($list)->pluck('code')->contains('TZS')
                    && collect($list)->every(fn ($row) => $row['perUsd'] > 0)));
    }

    public function test_it_rejects_a_currency_with_no_exchange_rate(): void
    {
        // Three letters, but nothing can convert to it, so no gateway could
        // charge in it.
        $this->saveShop(['currency' => 'ABC'])->assertSessionHasErrors('currency');
        $this->saveShop(['currency' => 'TZSS'])->assertSessionHasErrors('currency');

        $this->assertNotSame('ABC', BotSettings::for($this->tenant->id, 'order')['shop']['currency']);
    }

    public function test_an_old_unsupported_currency_does_not_block_saving_other_settings(): void
    {
        $settings = BotSettings::for($this->tenant->id, 'order');
        $settings['shop']['currency'] = 'ZZZ';
        BotSettings::save($this->tenant->id, 'order', $settings);

        // Kept as it is: the form sends back what it was given.
        $this->saveShop(['currency' => 'ZZZ', 'minTopup' => 3])->assertSessionHasNoErrors();

        $saved = BotSettings::for($this->tenant->id, 'order');
        $this->assertSame('ZZZ', $saved['shop']['currency']);
        $this->assertEquals(3, $saved['shop']['min_topup']);

        // But another unsupported one cannot be newly chosen.
        $this->saveShop(['currency' => 'YYY'])->assertSessionHasErrors('currency');
    }

    public function test_it_rejects_an_unsupported_language(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('order-bot.settings'), [
                'staff' => [],
                'testNumbers' => [],
                'currency' => 'USD',
                'lang' => 'xx',
                'minTopup' => 1,
                'referralPercent' => 0,
                'showProviderName' => false,
                'detailedStatus' => true,
            ])
            ->assertSessionHasErrors('lang');
    }

    public function test_logs_show_this_bots_messages_only(): void
    {
        $this->logMessage($this->tenant, 'order', '255700000003', 'I want followers');
        $this->logMessage($this->tenant, 'support', '255700000004', 'My order is late');

        $this->actingAs($this->tenant)
            ->get(route('order-bot', 'logs'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.total', 1)
                ->where('logs.rows.0.message', 'I want followers'),
            );
    }

    public function test_logs_do_not_leak_another_tenants_messages(): void
    {
        $other = Tenant::factory()->create();

        $this->logMessage($other, 'order', '255700000009', 'Not yours');

        $this->actingAs($this->tenant)
            ->get(route('order-bot', 'logs'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('logs.total', 0));
    }

    public function test_logs_can_be_searched(): void
    {
        $this->logMessage($this->tenant, 'order', '255700000005', 'Instagram likes please');
        $this->logMessage($this->tenant, 'order', '255700000006', 'TikTok views');

        $this->actingAs($this->tenant)
            ->get(route('order-bot', 'logs').'?q=instagram')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.total', 1)
                ->where('logs.rows.0.message', 'Instagram likes please'),
            );
    }
}
