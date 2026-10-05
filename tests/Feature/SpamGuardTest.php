<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotHandlerFactory;
use App\Services\Bots\BotRoute;
use App\Services\Bots\BotRouter;
use App\Services\Bots\BotSettings;
use App\Services\Bots\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeBotHandler;
use Tests\Support\FakeBotMessengerFactory;
use Tests\Support\FakeOrderBotHandler;
use Tests\Support\FakeSupportBotHandler;
use Tests\TestCase;

/**
 * The anti-spam guard blocks someone repeating themselves, not someone ordering.
 *
 * It used to count every message from a number, so a customer working through
 * an ordinary order was silenced on their fourth tap and the bot appeared to
 * hang. A real order is eight or more messages, all different.
 */
class SpamGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantWhatsApp $number;

    protected function setUp(): void
    {
        parent::setUp();

        FakeBotHandler::reset();
        RateLimiter::clear('x');
        Cache::flush();

        $this->tenant = Tenant::factory()->create();
        $this->number = TenantWhatsApp::factory()->for($this->tenant)->orderOnly()->create();
        Subscription::factory()->for($this->tenant)->active()->create(['service_key' => ServiceKey::OrderBot]);
    }

    private function send(string $text, string $from = '255700000001'): BotRoute
    {
        $handlers = new BotHandlerFactory;
        $handlers->register('order', FakeOrderBotHandler::class);
        $handlers->register('support', FakeSupportBotHandler::class);

        return (new BotRouter(new FakeBotMessengerFactory, $handlers))->route(
            new InboundMessage($this->number->phone_number_id, $from, $text, 'wamid.'.uniqid()),
        );
    }

    public function test_an_ordinary_order_is_never_mistaken_for_spam(): void
    {
        // Every message here is different, as they are in a real order.
        foreach (['Hi', 'main:new_order', 'plat_Instagram', 'cat_Followers', 'svc_241', 'qty_1000', 'https://instagram.com/x', 'confirm_yes', 'Hi', 'main:track'] as $text) {
            $this->assertSame(BotRoute::HandledOrder, $this->send($text), "'{$text}' was refused");
        }
    }

    public function test_the_same_message_over_and_over_is_blocked(): void
    {
        $this->assertSame(BotRoute::HandledOrder, $this->send('buy'));
        $this->assertSame(BotRoute::HandledOrder, $this->send('buy'));
        $this->assertSame(BotRoute::HandledOrder, $this->send('buy'));

        $this->assertSame(BotRoute::SpamBlocked, $this->send('buy'));
    }

    public function test_case_and_spacing_do_not_make_a_message_different(): void
    {
        foreach (['buy', 'BUY', ' buy '] as $text) {
            $this->send($text);
        }

        $this->assertSame(BotRoute::SpamBlocked, $this->send('Buy'));
    }

    public function test_a_blocked_sender_stays_blocked_whatever_they_say_next(): void
    {
        foreach (range(1, 4) as $_) {
            $this->send('buy');
        }

        // The block is for a time, not for one phrase.
        $this->assertSame(BotRoute::SpamBlocked, $this->send('something different'));
    }

    public function test_one_senders_block_does_not_touch_another(): void
    {
        foreach (range(1, 4) as $_) {
            $this->send('buy', '255700000001');
        }

        $this->assertSame(BotRoute::HandledOrder, $this->send('buy', '255700000002'));
    }

    public function test_staff_are_never_blocked(): void
    {
        BotSettings::save($this->tenant->id, 'order', ['staff' => ['numbers' => ['255700000001']]]);

        foreach (range(1, 8) as $_) {
            $this->assertSame(BotRoute::HandledOrder, $this->send('buy'));
        }
    }

    public function test_it_can_be_switched_off(): void
    {
        BotSettings::save($this->tenant->id, 'order', ['spam' => ['enabled' => false]]);

        foreach (range(1, 8) as $_) {
            $this->assertSame(BotRoute::HandledOrder, $this->send('buy'));
        }
    }

    public function test_the_threshold_is_the_resellers_to_set(): void
    {
        BotSettings::save($this->tenant->id, 'order', ['spam' => ['enabled' => true, 'repeat_threshold' => 5]]);

        foreach (range(1, 5) as $_) {
            $this->assertSame(BotRoute::HandledOrder, $this->send('buy'));
        }

        $this->assertSame(BotRoute::SpamBlocked, $this->send('buy'));
    }
}
