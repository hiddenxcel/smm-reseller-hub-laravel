<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotConversation;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotHandlerFactory;
use App\Services\Bots\BotRoute;
use App\Services\Bots\BotRouter;
use App\Services\Bots\BotSettings;
use App\Services\Bots\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBotHandler;
use Tests\Support\FakeBotMessengerFactory;
use Tests\Support\FakeOrderBotHandler;
use Tests\Support\FakeSupportBotHandler;
use Tests\TestCase;

/**
 * The router decides whose bot runs, and whether it may. Getting it wrong
 * either serves an unpaid reseller or silences a paying one, so each branch
 * is pinned here.
 */
class BotRouterTest extends TestCase
{
    use RefreshDatabase;

    private FakeBotMessengerFactory $messengers;

    protected function setUp(): void
    {
        parent::setUp();

        FakeBotHandler::reset();
        $this->messengers = new FakeBotMessengerFactory;
    }

    private function router(): BotRouter
    {
        $handlers = new BotHandlerFactory;
        $handlers->register('order', FakeOrderBotHandler::class);
        $handlers->register('support', FakeSupportBotHandler::class);

        return new BotRouter($this->messengers, $handlers);
    }

    private function message(TenantWhatsApp $number, string $text = 'hi', string $from = '255700000001'): InboundMessage
    {
        return new InboundMessage(
            phoneNumberId: $number->phone_number_id,
            from: $from,
            text: $text,
            providerMessageId: 'wamid.TEST',
        );
    }

    private function activate(Tenant $tenant, ServiceKey $service): void
    {
        Subscription::factory()->for($tenant)->active()->create(['service_key' => $service]);
    }

    // ---- routing to a tenant --------------------------------------------

    public function test_an_unknown_phone_number_id_routes_nowhere(): void
    {
        $route = $this->router()->route(new InboundMessage('does-not-exist', '255700000001', 'hi'));

        $this->assertSame(BotRoute::UnknownNumber, $route);
        $this->assertNull(FakeBotHandler::dispatchedTo());
    }

    public function test_a_suspended_tenants_bot_stops_immediately(): void
    {
        $tenant = Tenant::factory()->suspended()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->create();
        $this->activate($tenant, ServiceKey::OrderBot);

        $route = $this->router()->route($this->message($number));

        $this->assertSame(BotRoute::TenantInactive, $route);
        $this->assertNull(FakeBotHandler::dispatchedTo());
    }

    public function test_it_dispatches_to_the_tenant_that_owns_the_number(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();
        $this->activate($alice, ServiceKey::OrderBot);
        $this->activate($bob, ServiceKey::OrderBot);

        $alicesNumber = TenantWhatsApp::factory()->for($alice)->orderOnly()->create();
        TenantWhatsApp::factory()->for($bob)->orderOnly()->create();

        $this->router()->route($this->message($alicesNumber));

        $this->assertSame($alice->id, FakeBotHandler::$handled[0]['tenantId']);
    }

    // ---- the subscription gate ------------------------------------------

    public function test_an_active_subscription_lets_the_bot_run(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
        $this->activate($tenant, ServiceKey::OrderBot);

        $route = $this->router()->route($this->message($number));

        $this->assertSame(BotRoute::HandledOrder, $route);
        $this->assertSame('order', FakeBotHandler::dispatchedTo());
    }

    public function test_no_subscription_locks_the_gate_and_tells_the_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();

        $route = $this->router()->route($this->message($number));

        $this->assertSame(BotRoute::GateLocked, $route);
        $this->assertNull(FakeBotHandler::dispatchedTo());
        $this->assertStringContainsString('paused', (string) $this->messengers->messenger->lastBody());
    }

    public function test_a_locked_gate_stays_silent_when_there_is_no_token_to_reply_with(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->withoutToken()->create();

        $route = $this->router()->route($this->message($number));

        $this->assertSame(BotRoute::GateLocked, $route);
        $this->assertTrue($this->messengers->messenger->nothingSent());
    }

    public function test_sandbox_alone_does_not_open_the_gate(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
        Subscription::factory()->for($tenant)->sandbox()->create(['service_key' => ServiceKey::OrderBot]);

        $route = $this->router()->route($this->message($number, from: '255700000009'));

        $this->assertSame(BotRoute::GateLocked, $route);
    }

    public function test_a_registered_test_number_may_exercise_a_sandbox_service(): void
    {
        // How a reseller tries the bot before paying.
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
        Subscription::factory()->for($tenant)->sandbox()->create(['service_key' => ServiceKey::OrderBot]);

        BotSettings::save($tenant->id, 'order', [
            'shop' => ['test_numbers' => ['255700000001']],
        ]);

        $route = $this->router()->route($this->message($number, from: '255700000001'));

        $this->assertSame(BotRoute::HandledOrder, $route);
    }

    public function test_the_gate_is_per_service(): void
    {
        // Support is paid for, the order bot is not.
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
        $this->activate($tenant, ServiceKey::SupportBot);

        $this->assertSame(BotRoute::GateLocked, $this->router()->route($this->message($number)));
    }

    public function test_a_both_number_falls_back_to_the_bot_that_is_paid_for(): void
    {
        // Only support is active, but the message would normally open the
        // order bot — falling back beats going silent.
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->create(['bot_type' => 'both']);
        $this->activate($tenant, ServiceKey::SupportBot);

        $route = $this->router()->route($this->message($number, 'i want followers'));

        $this->assertSame(BotRoute::HandledSupport, $route);
    }

    // ---- picking a bot on a "both" number --------------------------------

    public function test_a_fresh_message_opens_the_order_bot(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->create(['bot_type' => 'both']);
        $this->activate($tenant, ServiceKey::OrderBot);
        $this->activate($tenant, ServiceKey::SupportBot);

        $this->router()->route($this->message($number, 'hello'));

        $this->assertSame('order', FakeBotHandler::dispatchedTo());
    }

    public function test_a_support_word_opens_the_support_bot(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->create(['bot_type' => 'both']);
        $this->activate($tenant, ServiceKey::OrderBot);
        $this->activate($tenant, ServiceKey::SupportBot);

        $this->router()->route($this->message($number, 'refill my order 123'));

        $this->assertSame('support', FakeBotHandler::dispatchedTo());
    }

    public function test_a_support_word_must_be_the_first_word(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->create(['bot_type' => 'both']);
        $this->activate($tenant, ServiceKey::OrderBot);
        $this->activate($tenant, ServiceKey::SupportBot);

        // "status" appears, but the customer is clearly ordering.
        $this->router()->route($this->message($number, 'i want 500 followers status unknown'));

        $this->assertSame('order', FakeBotHandler::dispatchedTo());
    }

    public function test_an_in_progress_order_keeps_an_ambiguous_reply_with_the_order_bot(): void
    {
        // The reason stickiness exists: mid-order, "refill" is likely part of
        // a service name, not a support request.
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->create(['bot_type' => 'both']);
        $this->activate($tenant, ServiceKey::OrderBot);
        $this->activate($tenant, ServiceKey::SupportBot);

        BotConversation::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '255700000001',
            'bot_type' => 'order',
            'state' => 'SELECT_SERVICE',
        ]);

        $this->router()->route($this->message($number, 'refill'));

        $this->assertSame('order', FakeBotHandler::dispatchedTo());
    }

    public function test_an_idle_conversation_does_not_hold_the_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->create(['bot_type' => 'both']);
        $this->activate($tenant, ServiceKey::OrderBot);
        $this->activate($tenant, ServiceKey::SupportBot);

        BotConversation::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '255700000001',
            'bot_type' => 'order',
            'state' => 'IDLE',
        ]);

        $this->router()->route($this->message($number, 'refill'));

        $this->assertSame('support', FakeBotHandler::dispatchedTo());
    }

    public function test_an_order_only_number_never_reaches_support(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
        $this->activate($tenant, ServiceKey::OrderBot);
        $this->activate($tenant, ServiceKey::SupportBot);

        $this->router()->route($this->message($number, 'refill'));

        $this->assertSame('order', FakeBotHandler::dispatchedTo());
    }

    // ---- side effects ----------------------------------------------------

    public function test_it_logs_the_inbound_message_against_the_bot_that_took_it(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
        $this->activate($tenant, ServiceKey::OrderBot);

        $this->router()->route($this->message($number, 'hello there'));

        $this->assertDatabaseHas('bot_messages', [
            'tenant_id' => $tenant->id,
            'customer_phone' => '255700000001',
            'direction' => 'in',
            'message' => 'hello there',
            // Without this the log cannot say which bot a message belonged
            // to, so nothing downstream can report the two separately.
            'bot_type' => 'order',
        ]);
    }

    public function test_a_shared_number_logs_the_bot_the_message_was_routed_to(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->create(['bot_type' => 'both']);
        $this->activate($tenant, ServiceKey::OrderBot);
        $this->activate($tenant, ServiceKey::SupportBot);

        $this->router()->route($this->message($number, 'refill please'));
        $this->router()->route($this->message($number, 'I need 500 followers'));

        $this->assertDatabaseHas('bot_messages', [
            'message' => 'refill please',
            'bot_type' => 'support',
        ]);

        $this->assertDatabaseHas('bot_messages', [
            'message' => 'I need 500 followers',
            'bot_type' => 'order',
        ]);
    }

    public function test_a_message_refused_by_the_gate_is_still_logged(): void
    {
        // The ones that went unanswered are exactly the ones a reseller
        // asking "did anyone message me?" needs to see.
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();

        $this->router()->route($this->message($number, 'anyone there?'));

        $this->assertDatabaseHas('bot_messages', [
            'tenant_id' => $tenant->id,
            'direction' => 'in',
            'message' => 'anyone there?',
            'bot_type' => 'order',
        ]);
    }

    public function test_it_marks_the_message_read(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
        $this->activate($tenant, ServiceKey::OrderBot);

        $this->router()->route($this->message($number));

        $this->assertContains('wamid.TEST', $this->messengers->messenger->markedRead);
    }

    public function test_it_passes_the_message_through_to_the_handler(): void
    {
        $tenant = Tenant::factory()->create();
        $number = TenantWhatsApp::factory()->for($tenant)->orderOnly()->create();
        $this->activate($tenant, ServiceKey::OrderBot);

        $this->router()->route($this->message($number, 'instagram'));

        $this->assertSame('instagram', FakeBotHandler::$handled[0]['text']);
        $this->assertSame('255700000001', FakeBotHandler::$handled[0]['from']);
    }
}
