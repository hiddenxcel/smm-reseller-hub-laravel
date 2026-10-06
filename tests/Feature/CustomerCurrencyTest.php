<?php

namespace Tests\Feature;

use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotService;
use App\Models\Tenant;
use App\Services\Bots\BotSettings;
use App\Services\Bots\Order\OrderBotHandler;
use App\Services\Bots\Order\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * A customer can pick the currency they see prices in — Settings, next to
 * language. It is for looking at: the wallet, the orders and every payment stay
 * in the shop's own currency, so a change in the exchange rate can never change
 * what anyone owes.
 */
class CustomerCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '255700000001';

    private Tenant $tenant;

    private BotCustomer $customer;

    private FakeBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['currency.usd_to' => ['USD' => 1.0, 'TZS' => 2600.0, 'KES' => 130.0, 'EUR' => 0.9]]);

        $this->tenant = Tenant::factory()->create();
        $this->customer = BotCustomer::factory()->for($this->tenant)->create([
            'phone' => self::PHONE,
            'balance' => '4.00',
            'total_spent' => '10.00',
        ]);
        $this->messenger = new FakeBotMessenger;
    }

    private function say(string $text): void
    {
        (new OrderBotHandler($this->tenant, $this->messenger))->handle(self::PHONE, $text);
    }

    private function last(): array
    {
        return end($this->messenger->sent);
    }

    private function state(): ?string
    {
        return BotConversation::current($this->tenant->id, self::PHONE, 'order')?->state;
    }

    private function chooseCurrency(string $code): void
    {
        $this->say('hi');
        $this->say('main:settings');
        $this->say('set:currency');
        $this->say("cur:{$code}");
    }

    // ---- the menu --------------------------------------------------------

    public function test_settings_offers_language_and_currency(): void
    {
        $this->say('hi');
        $this->say('main:settings');

        $this->assertSame('buttons', $this->last()['type']);
        $this->assertSame(['set:language', 'set:currency'], array_column($this->last()['buttons'], 'id'));
        $this->assertSame(OrderState::SettingsMenu->value, $this->state());
    }

    public function test_language_still_works_from_settings(): void
    {
        $this->say('hi');
        $this->say('main:settings');
        $this->say('set:language');

        $this->assertSame(OrderState::SelectLanguage->value, $this->state());
        $this->assertSame('list', $this->last()['type']);

        $this->say('lang:sw');

        $this->assertSame('sw', $this->customer->fresh()->lang);
    }

    public function test_a_stray_message_in_settings_asks_which_to_change(): void
    {
        $this->say('hi');
        $this->say('main:settings');
        $this->say('blah');

        $this->assertSame(OrderState::SettingsMenu->value, $this->state());
        $this->assertStringContainsString('Language', $this->last()['body']);
    }

    public function test_the_currency_list_puts_the_shops_currency_first(): void
    {
        $this->say('hi');
        $this->say('main:settings');
        $this->say('set:currency');

        $rows = collect($this->last()['rows']);

        $this->assertSame('cur:USD', $rows->first()['id']);
        $this->assertSame("The shop's currency", $rows->first()['description']);
        $this->assertSame(4, $rows->count());
        $this->assertLessThanOrEqual(10, $rows->count());
    }

    // ---- choosing --------------------------------------------------------

    public function test_choosing_a_currency_is_remembered(): void
    {
        $this->chooseCurrency('TZS');

        $this->assertSame('TZS', $this->customer->fresh()->currency);
        $this->assertStringContainsString('TZS', $this->messenger->sent[count($this->messenger->sent) - 2]['body']);
    }

    public function test_a_currency_can_be_typed_when_it_did_not_fit_the_list(): void
    {
        $this->say('hi');
        $this->say('main:settings');
        $this->say('set:currency');
        $this->say(' kes ');

        $this->assertSame('KES', $this->customer->fresh()->currency);
    }

    public function test_a_currency_with_no_rate_is_refused(): void
    {
        $this->say('hi');
        $this->say('main:settings');
        $this->say('set:currency');
        $this->say('XYZ');

        $this->assertNull($this->customer->fresh()->currency);
        $this->assertSame(OrderState::SelectCurrency->value, $this->state());
    }

    public function test_choosing_the_shops_own_currency_means_no_preference(): void
    {
        $this->customer->update(['currency' => 'TZS']);

        $this->chooseCurrency('USD');

        $this->assertNull($this->customer->fresh()->currency);
    }

    public function test_one_customers_choice_does_not_touch_anothers(): void
    {
        $other = BotCustomer::factory()->for($this->tenant)->create(['phone' => '255700000002']);

        $this->chooseCurrency('TZS');

        $this->assertNull($other->fresh()->currency);
    }

    // ---- what they then see ----------------------------------------------

    public function test_their_balance_is_shown_in_their_currency_and_marked_approximate(): void
    {
        $this->chooseCurrency('TZS');
        $this->say('main:profile');

        // 4.00 USD at 2,600 — whole shillings, with a ≈ because it is converted.
        $this->assertStringContainsString('≈ TZS 10,400', $this->last()['body']);
        $this->assertStringContainsString('≈ TZS 26,000', $this->last()['body']);
        $this->assertStringNotContainsString('USD 4.00', $this->last()['body']);
    }

    public function test_without_a_choice_amounts_are_in_the_shops_currency_as_before(): void
    {
        $this->say('hi');
        $this->say('main:profile');

        $this->assertStringContainsString('USD 4.00', $this->last()['body']);
        $this->assertStringNotContainsString('≈', $this->last()['body']);
    }

    public function test_a_currency_with_cents_keeps_them(): void
    {
        $this->chooseCurrency('EUR');
        $this->say('main:profile');

        $this->assertStringContainsString('≈ EUR 3.60', $this->last()['body']);
    }

    public function test_service_prices_in_the_list_follow_their_currency(): void
    {
        BotService::factory()->for($this->tenant)->create([
            'platform' => 'Instagram', 'category' => 'Followers', 'name' => 'IG Followers', 'my_price' => '4.0000',
        ]);
        $this->chooseCurrency('TZS');

        $this->say('hi');
        $this->say('main:new_order');
        $this->say('plat_Instagram');
        $this->say('cat_Followers');

        $this->assertSame('≈ TZS 10,400 / 1k', $this->last()['rows'][0]['description']);
    }

    public function test_what_is_paid_stays_in_the_shops_currency(): void
    {
        // The money a customer is asked to top up is never shown in an
        // approximate currency: the figure on the payment is what is charged.
        BotService::factory()->for($this->tenant)->create([
            'platform' => 'Instagram', 'category' => 'Followers', 'name' => 'IG Followers',
            'my_price' => '50.0000', 'min_quantity' => 100, 'max_quantity' => 100000,
        ]);
        $this->customer->update(['balance' => '1.00', 'currency' => 'TZS']);

        $this->say('hi');
        $this->say('main:new_order');
        $this->say('plat_Instagram');
        $this->say('cat_Followers');
        $this->say('svc_'.array_key_first(BotConversation::current($this->tenant->id, self::PHONE, 'order')->context['services']));
        $this->say('qty_1000');
        $this->say('https://instagram.com/someone');
        $this->say('confirm_yes');

        $body = $this->last()['body'];
        $this->assertStringContainsString('USD', $body);
        $this->assertStringNotContainsString('≈', $body);
    }

    public function test_if_the_shop_changes_currency_a_stale_choice_is_followed_not_misread(): void
    {
        $this->customer->update(['currency' => 'KES']);
        $settings = BotSettings::for($this->tenant->id, 'order');
        Arr::set($settings, 'shop.currency', 'KES');
        BotSettings::save($this->tenant->id, 'order', $settings);

        $this->say('hi');
        $this->say('main:profile');

        // Their choice is now the shop's own currency: shown exactly, no ≈.
        $this->assertStringContainsString('KES', $this->last()['body']);
        $this->assertStringNotContainsString('≈', $this->last()['body']);
    }

    public function test_a_currency_whose_rate_has_been_removed_falls_back_to_the_shops(): void
    {
        $this->customer->update(['currency' => 'EUR']);
        config(['currency.usd_to' => ['USD' => 1.0, 'TZS' => 2600.0]]);

        $this->say('hi');
        $this->say('main:profile');

        $this->assertStringContainsString('USD 4.00', $this->last()['body']);
    }

    public function test_with_only_one_currency_settings_goes_straight_to_language(): void
    {
        config(['currency.usd_to' => ['USD' => 1.0]]);

        $this->say('hi');
        $this->say('main:settings');

        $this->assertSame(OrderState::SelectLanguage->value, $this->state());
    }

    // ---- more currencies than a list can hold -----------------------------

    /** Twenty-five currencies: USD first, then XAA…XAX. */
    private function manyCurrencies(): array
    {
        $rates = ['USD' => 1.0];

        for ($i = 0; $i < 24; $i++) {
            $rates['X'.chr(65 + intdiv($i, 26)).chr(65 + $i % 26)] = 2.0 + $i;
        }

        config(['currency.usd_to' => $rates, 'currency.names' => array_combine(array_keys($rates), array_keys($rates))]);

        return array_keys($rates);
    }

    private function openCurrencyList(): void
    {
        $this->say('hi');
        $this->say('main:settings');
        $this->say('set:currency');
    }

    public function test_a_long_list_shows_nine_currencies_and_a_way_to_the_next_nine(): void
    {
        $this->manyCurrencies();
        $this->openCurrencyList();

        $rows = collect($this->last()['rows']);

        $this->assertSame(10, $rows->count());
        $this->assertSame('cur:USD', $rows->first()['id']);
        $this->assertSame('cur_more', $rows->last()['id']);
        $this->assertSame('Page 2 of 3', $rows->last()['description']);
        $this->assertStringContainsString('Page 1 of 3', $this->last()['body']);
    }

    public function test_more_shows_the_next_page_and_the_last_page_leads_back_to_the_first(): void
    {
        $this->manyCurrencies();
        $this->openCurrencyList();

        $this->say('cur_more');
        $second = collect($this->last()['rows']);
        $this->assertSame('cur_more', $second->last()['id']);
        $this->assertStringContainsString('Page 2 of 3', $this->last()['body']);

        $this->say('cur_more');
        $third = collect($this->last()['rows']);
        $this->assertSame('cur_first', $third->last()['id']);

        $this->say('cur_first');
        $this->assertSame('cur:USD', $this->last()['rows'][0]['id']);
    }

    public function test_every_currency_is_reachable_exactly_once_through_the_pages(): void
    {
        $all = $this->manyCurrencies();
        $this->openCurrencyList();

        $seen = [];

        for ($page = 0; $page < 3; $page++) {
            foreach ($this->last()['rows'] as $row) {
                if (str_starts_with($row['id'], 'cur:')) {
                    $seen[] = substr($row['id'], 4);
                }
            }

            if ($page < 2) {
                $this->say('cur_more');
            }
        }

        sort($all);
        sort($seen);
        $this->assertSame($all, $seen);
    }

    public function test_a_currency_can_be_chosen_from_a_later_page(): void
    {
        $this->manyCurrencies();
        $this->openCurrencyList();
        $this->say('cur_more');
        $this->say('cur_more');

        $row = collect($this->last()['rows'])->first(fn ($r) => str_starts_with($r['id'], 'cur:'));
        $this->say($row['id']);

        $this->assertSame(substr($row['id'], 4), $this->customer->fresh()->currency);
        $this->assertSame(OrderState::MainMenu->value, $this->state());
    }

    public function test_a_typed_code_works_from_any_page(): void
    {
        $this->manyCurrencies();
        $this->openCurrencyList();
        $this->say('cur_more');

        $this->say('xax');

        $this->assertSame('XAX', $this->customer->fresh()->currency);
    }

    public function test_a_list_that_fits_has_no_more_row(): void
    {
        config(['currency.usd_to' => ['USD' => 1.0, 'TZS' => 2600.0, 'KES' => 130.0], 'currency.names' => []]);
        $this->openCurrencyList();

        $ids = array_column($this->last()['rows'], 'id');

        $this->assertNotContains('cur_more', $ids);
        $this->assertStringNotContainsString('Page 1', $this->last()['body']);
    }

    // ---- the currencies themselves ----------------------------------------

    public function test_the_real_list_is_long_and_every_entry_is_complete(): void
    {
        $real = require base_path('config/currency.php');
        $rates = $real['usd_to'];
        $names = $real['names'];

        $this->assertGreaterThan(50, count($rates));
        $this->assertSame([], array_diff(array_keys($rates), array_keys($names)), 'a rate with no name');
        $this->assertSame([], array_diff(array_keys($names), array_keys($rates)), 'a name with no rate');

        foreach ($rates as $code => $rate) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', (string) $code);
            $this->assertGreaterThan(0, $rate, $code);
            $this->assertNotSame('', trim((string) $names[$code]), $code);
        }

        $this->assertSame(1.0, (float) $rates['USD']);
    }

    public function test_the_pegged_currencies_have_their_exact_rates(): void
    {
        $rates = (require base_path('config/currency.php'))['usd_to'];

        $this->assertSame(3.6725, (float) $rates['AED']);
        $this->assertSame(3.75, (float) $rates['SAR']);
        $this->assertSame($rates['XAF'], $rates['XOF']);
    }
}
