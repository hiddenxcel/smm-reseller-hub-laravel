<?php

namespace Tests\Feature;

use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotService;
use App\Models\Tenant;
use App\Services\Bots\Order\OrderBotHandler;
use App\Services\Bots\Order\OrderState;
use App\Services\Catalogue\ServiceFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * Before a customer chooses how many, they are shown what the service is: its
 * full name, what it costs, and the promises the reseller has made about it —
 * as one message, with the quantities as a second message beneath it. And they
 * can always step back.
 */
class ServiceCardTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '255700000001';

    private Tenant $tenant;

    private FakeBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::factory()->create(['business_name' => 'Kuza Panel']);
        BotCustomer::factory()->for($this->tenant)->create(['phone' => self::PHONE, 'balance' => '50.00']);
        $this->messenger = new FakeBotMessenger;
    }

    private function say(string $text): void
    {
        (new OrderBotHandler($this->tenant, $this->messenger))->handle(self::PHONE, $text);
    }

    private function service(array $attributes = []): BotService
    {
        return BotService::factory()->for($this->tenant)->create([
            'platform' => 'Instagram',
            'category' => 'Followers',
            'unit_label' => 'Followers',
            'name' => 'Instagram Followers | Real Mobile App | No Drop | 365 Days Refill',
            'my_price' => '3.9000',
            'min_quantity' => 100,
            'max_quantity' => 50000,
            ...$attributes,
        ]);
    }

    /** Walk to the point just after choosing the service. */
    private function chooseService(BotService $service): void
    {
        $this->say('hi');
        $this->say('main:new_order');
        $this->say('plat_Instagram');
        $this->say('cat_'.$service->category);
        $this->messenger->sent = [];
        $this->say("svc_{$service->id}");
    }

    private function state(): ?string
    {
        return BotConversation::current($this->tenant->id, self::PHONE, 'order')?->state;
    }

    // ---- the card --------------------------------------------------------

    public function test_choosing_a_service_sends_the_card_and_then_the_quantities(): void
    {
        $this->chooseService($this->service());

        $this->assertCount(2, $this->messenger->sent);
        $this->assertSame('text', $this->messenger->sent[0]['type']);
        $this->assertSame('list', $this->messenger->sent[1]['type']);
        $this->assertSame(OrderState::SelectQuantity->value, $this->state());
    }

    public function test_the_card_shows_the_full_name_and_the_price(): void
    {
        $service = $this->service();
        $this->chooseService($service);

        $card = $this->messenger->sent[0]['body'];

        // The whole name, not the 24 characters a list row had room for.
        $this->assertStringContainsString('*'.$service->name.'*', $card);
        $this->assertStringContainsString('USD 3.90 per 1K', $card);
        $this->assertStringContainsString('100 – 50,000', $card);
    }

    public function test_the_card_shows_what_the_reseller_filled_in(): void
    {
        $this->chooseService($this->service([
            'description' => 'Real-looking accounts with profile pictures.',
            'quality' => 'High quality',
            'speed' => '1–6 hours',
            'drop_info' => 'No drop',
            'refill_info' => '365 days',
        ]));

        $card = $this->messenger->sent[0]['body'];

        $this->assertStringContainsString('Real-looking accounts with profile pictures.', $card);
        $this->assertStringContainsString('*Quality:* High quality', $card);
        $this->assertStringContainsString('*Speed:* 1–6 hours', $card);
        $this->assertStringContainsString('*Drop:* No drop', $card);
        $this->assertStringContainsString('*Refill:* 365 days', $card);
    }

    public function test_drop_and_refill_are_shown_in_the_resellers_own_words(): void
    {
        $this->chooseService($this->service([
            'drop_info' => 'Low drop, 5% at most',
            'refill_info' => 'Free for 30 days, then 2.00 per refill',
        ]));

        $card = $this->messenger->sent[0]['body'];

        $this->assertStringContainsString('*Drop:* Low drop, 5% at most', $card);
        $this->assertStringContainsString('*Refill:* Free for 30 days, then 2.00 per refill', $card);
    }
    public function test_a_line_nobody_filled_in_is_not_shown(): void
    {
        $this->chooseService($this->service());

        $card = $this->messenger->sent[0]['body'];

        $this->assertStringNotContainsString('Quality', $card);
        $this->assertStringNotContainsString('Speed', $card);
        $this->assertStringNotContainsString('Drop:', $card);
        $this->assertStringNotContainsString('Refill:', $card);
    }

    public function test_the_card_says_a_profile_link_is_needed_for_followers(): void
    {
        $this->chooseService($this->service(['category' => 'Followers']));

        $this->assertStringContainsString('Your profile link', $this->messenger->sent[0]['body']);
    }

    public function test_the_card_says_a_post_link_is_needed_for_likes(): void
    {
        $this->chooseService($this->service(['category' => 'Likes', 'name' => 'Instagram Likes']));

        $this->assertStringContainsString('Your post or video link', $this->messenger->sent[0]['body']);
    }
    public function test_the_card_ends_by_inviting_a_quantity_and_explaining_how_to_go_back(): void
    {
        $this->chooseService($this->service());

        $card = $this->messenger->sent[0]['body'];

        $this->assertStringContainsString('Choose a quantity below', $card);
        $this->assertStringContainsString('*back*', $card);
    }

    public function test_the_quantity_list_has_a_back_row(): void
    {
        $this->chooseService($this->service());

        $rows = collect($this->messenger->sent[1]['rows']);

        $this->assertSame('qty_back', $rows->last()['id']);
        $this->assertLessThanOrEqual(10, $rows->count());
    }

    // ---- going back ------------------------------------------------------

    public function test_back_returns_to_the_list_of_services(): void
    {
        $this->chooseService($this->service());
        $this->messenger->sent = [];

        $this->say('back');

        $this->assertSame(OrderState::SelectService->value, $this->state());
        $this->assertSame('list', $this->messenger->sent[0]['type']);
        $this->assertSame('SELECT_SERVICE', $this->messenger->sent[0]['templateKey']);
    }

    public function test_every_way_of_saying_back_works(): void
    {
        foreach (['back', 'BACK', ' Back ', '0', '⬅️', '←', 'rudi', 'qty_back'] as $word) {
            BotConversation::clear($this->tenant->id, self::PHONE, 'order');
            BotService::query()->delete();
            $this->chooseService($this->service());

            $this->say($word);

            $this->assertSame(OrderState::SelectService->value, $this->state(), "'{$word}' did not go back");
        }
    }

    public function test_back_does_not_place_anything_or_ask_for_a_quantity_again(): void
    {
        $this->chooseService($this->service());

        $this->say('back');

        $this->assertSame(0, \App\Models\BotOrder::withoutTenantScope()->count());
    }

    public function test_back_from_the_link_step_returns_to_the_quantities(): void
    {
        $this->chooseService($this->service());
        $this->say('qty_500');
        $this->assertSame(OrderState::SendLink->value, $this->state());
        $this->messenger->sent = [];

        $this->say('back');

        $this->assertSame(OrderState::SelectQuantity->value, $this->state());
        // The card and the quantities again.
        $this->assertSame(['text', 'list'], array_column($this->messenger->sent, 'type'));
    }

    public function test_a_real_quantity_still_works_after_the_card(): void
    {
        $this->chooseService($this->service());

        $this->say('qty_1000');

        $this->assertSame(OrderState::SendLink->value, $this->state());
    }

    public function test_a_quantity_out_of_range_is_still_refused(): void
    {
        $this->chooseService($this->service());

        $this->say('5');

        $this->assertSame(OrderState::SelectQuantity->value, $this->state());
    }

    // ---- reading the promises off a name ---------------------------------

    public function test_no_drop_and_refill_days_are_read_from_a_name(): void
    {
        $this->assertSame(
            ['drop_info' => 'No drop', 'refill_info' => '365 days'],
            ServiceFeatures::guess('Instagram Followers | %100 Real | Mobil App | No Drop | 365 Days Refill ♻️'),
        );
    }

    public function test_the_common_spellings_are_understood(): void
    {
        $this->assertSame('No drop', ServiceFeatures::guess('IG Followers NonDrop')['drop_info']);
        $this->assertSame('No drop', ServiceFeatures::guess('IG Followers Non-Drop')['drop_info']);
        $this->assertSame('30 days', ServiceFeatures::guess('IG Followers 30D Refill')['refill_info']);
        $this->assertSame('60 days', ServiceFeatures::guess('IG Followers Refill 60 days')['refill_info']);
        $this->assertSame('365 days', ServiceFeatures::guess('IG Followers [R365]')['refill_info']);
        $this->assertSame('Lifetime', ServiceFeatures::guess('IG Followers Lifetime Refill')['refill_info']);
        $this->assertSame('No refill', ServiceFeatures::guess('IG Likes No Refill')['refill_info']);
    }

    public function test_a_name_that_says_nothing_gives_nothing(): void
    {
        $this->assertSame(
            ['drop_info' => null, 'refill_info' => null],
            ServiceFeatures::guess('Instagram Followers Cheap and Fast'),
        );
    }
    public function test_lifetime_alone_is_not_a_refill_promise(): void
    {
        $this->assertNull(ServiceFeatures::guess('Lifetime Member Special')['refill_info']);
    }

    public function test_existing_services_can_be_filled_in_from_their_names_without_touching_what_is_set(): void
    {
        $blank = BotService::factory()->for($this->tenant)->create(['name' => 'IG Followers | No Drop | 30 Days Refill']);
        $set = BotService::factory()->for($this->tenant)->create([
            'name' => 'IG Followers | No Drop | 30 Days Refill',
            'drop_info' => 'Low drop',
            'refill_info' => 'Lifetime, free',
        ]);
        $silent = BotService::factory()->for($this->tenant)->create(['name' => 'IG Followers cheap']);

        $this->artisan('services:guess-features')->expectsOutput('Filled in 1 service(s).')->assertSuccessful();

        $this->assertSame('No drop', $blank->fresh()->drop_info);
        $this->assertSame('30 days', $blank->fresh()->refill_info);
        $this->assertSame('Low drop', $set->fresh()->drop_info);
        $this->assertSame('Lifetime, free', $set->fresh()->refill_info);
        $this->assertNull($silent->fresh()->drop_info);
    }

    // ---- the price in the list --------------------------------------------

    public function test_the_service_list_shows_the_price_per_thousand_not_per_unit(): void
    {
        $this->service(['name' => 'Instagram Followers Real', 'my_price' => '4.0000']);
        $this->say('hi');
        $this->say('main:new_order');
        $this->say('plat_Instagram');
        $this->messenger->sent = [];
        $this->say('cat_Followers');

        $row = $this->messenger->sent[0]['rows'][0];

        // 4.00 per 1,000 — not 4.00 divided by 1,000 and called "per 1k".
        $this->assertSame('USD 4.00 / 1k', $row['description']);
    }

    public function test_a_cheap_service_is_not_rounded_down_to_nothing(): void
    {
        $this->service(['name' => 'Cheap Followers', 'my_price' => '0.3500']);
        $this->say('hi');
        $this->say('main:new_order');
        $this->say('plat_Instagram');
        $this->messenger->sent = [];
        $this->say('cat_Followers');

        $this->assertSame('USD 0.35 / 1k', $this->messenger->sent[0]['rows'][0]['description']);
    }
}
