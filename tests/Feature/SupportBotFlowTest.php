<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotConversation;
use App\Models\BotOrder;
use App\Models\GuaranteeRule;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantAi;
use App\Models\TenantPanel;
use App\Models\Ticket;
use App\Services\Bots\BotSettings;
use App\Services\Bots\Support\SupportBotHandler;
use App\Services\Bots\Support\SupportState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * After-sales support: the Quick Menu, and the panel actions behind it.
 */
class SupportBotFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '255700000001';

    private const STAFF = '255700000099';

    private Tenant $tenant;

    private FakeBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create(['business_name' => 'Kuza Panel']);
        $this->messenger = new FakeBotMessenger;
    }

    private function send(string $text): void
    {
        (new SupportBotHandler($this->tenant, $this->messenger))->handle(self::CUSTOMER, $text);
    }

    private function state(): ?string
    {
        return BotConversation::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('bot_type', 'support')
            ->value('state');
    }

    /** Messages sent to the customer, ignoring staff notifications. */
    private function toCustomer(): array
    {
        return array_values(array_filter(
            $this->messenger->sent,
            fn (array $message) => $message['to'] === self::CUSTOMER,
        ));
    }

    private function lastToCustomer(): string
    {
        $messages = $this->toCustomer();

        return $messages === [] ? '' : (string) end($messages)['body'];
    }

    private function withStaff(): void
    {
        BotSettings::save($this->tenant->id, 'support', [
            'staff' => ['numbers' => [self::STAFF]],
        ]);
    }

    private function withPanel(): TenantPanel
    {
        return TenantPanel::factory()->for($this->tenant)->create();
    }

    // ---- the menu ---------------------------------------------------------

    public function test_any_message_opens_the_quick_menu(): void
    {
        $this->send('hey there');

        $this->assertSame(SupportState::Menu->value, $this->state());
        $this->assertStringContainsString('Quick Menu', $this->lastToCustomer());
        $this->assertStringContainsString('Kuza Panel', $this->lastToCustomer());
    }

    public function test_cancel_closes_support(): void
    {
        $this->send('hi');
        $this->send('cancel');

        $this->assertNull($this->state());
        $this->assertStringContainsString('Support closed', $this->lastToCustomer());
    }

    public function test_zero_returns_to_the_menu(): void
    {
        $this->send('hi');
        $this->send('1');
        $this->assertSame(SupportState::AwaitOrderId->value, $this->state());

        $this->send('0');

        $this->assertSame(SupportState::Menu->value, $this->state());
    }

    public function test_an_unrecognised_choice_is_rejected(): void
    {
        $this->send('hi');
        $this->send('99');

        $this->assertStringContainsString('number from *1* to *8*', $this->lastToCustomer());
        $this->assertSame(SupportState::Menu->value, $this->state());
    }

    public function test_an_action_the_reseller_disabled_is_refused(): void
    {
        // speedup is off by default.
        $this->send('hi');
        $this->send('2');

        $this->assertStringContainsString("isn't available", $this->lastToCustomer());
        $this->assertSame(SupportState::Menu->value, $this->state());
    }

    // ---- order status -----------------------------------------------------

    public function test_it_reports_a_status_from_the_panel(): void
    {
        $this->withPanel();
        Http::fake(['*' => Http::response(['status' => 'In progress', 'remains' => 120])]);

        $this->send('hi');
        $this->send('6');
        $this->send('48220');

        $this->assertStringContainsString('In progress', json_encode($this->toCustomer()));
        $this->assertStringContainsString('Remaining: 120', json_encode($this->toCustomer()));
    }

    public function test_an_unknown_order_is_reported_as_not_found(): void
    {
        $this->withPanel();
        Http::fake(['*' => Http::response(['error' => 'Incorrect order ID'])]);

        $this->send('hi');
        $this->send('6');
        $this->send('99999');

        $this->assertStringContainsString('not found', json_encode($this->toCustomer()));
    }

    public function test_a_panel_action_without_a_connected_panel_bows_out(): void
    {
        // No panel connected at all.
        $this->send('hi');
        $this->send('6');
        $this->send('48220');

        $this->assertStringContainsString("isn't fully set up", $this->lastToCustomer());
        $this->assertNull($this->state());
    }

    public function test_a_blank_order_id_is_rejected_without_calling_the_panel(): void
    {
        $this->withPanel();
        Http::fake();

        $this->send('hi');
        $this->send('6');
        $this->send('???');

        $this->assertStringContainsString("doesn't look like an order ID", $this->lastToCustomer());
        Http::assertNothingSent();
    }

    // ---- refill, and its guarantee gate -----------------------------------

    public function test_a_refill_is_submitted_when_the_guarantee_allows_it(): void
    {
        $this->withPanel();
        $this->withStaff();

        BotOrder::factory()->for($this->tenant)->create([
            'provider_order_id' => '48220',
            'service_name' => 'IG Followers | 30 Days Refill',
        ]);
        GuaranteeRule::factory()->for($this->tenant)->create([
            'keyword' => '30 days',
            'refill_days' => 30,
        ]);

        Http::fake(['*' => Http::response(['refill' => '9001'])]);

        $this->send('hi');
        $this->send('1');
        $this->send('48220');

        $this->assertStringContainsString('Refill for *#48220* submitted', json_encode($this->toCustomer()));
        $this->assertStringContainsString('30 days', json_encode($this->toCustomer()));
    }

    public function test_a_refill_is_refused_when_the_service_has_no_guarantee(): void
    {
        $this->withPanel();

        BotOrder::factory()->for($this->tenant)->create([
            'provider_order_id' => '48220',
            'service_name' => 'IG Followers | No Refill',
        ]);
        GuaranteeRule::factory()->for($this->tenant)->noGuarantee()->create();

        Http::fake();

        $this->send('hi');
        $this->send('1');
        $this->send('48220');

        $this->assertStringContainsString('no refill guarantee', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }

    public function test_a_lifetime_guarantee_is_described_as_such(): void
    {
        $this->withPanel();

        BotOrder::factory()->for($this->tenant)->create([
            'provider_order_id' => '48220',
            'service_name' => 'IG Likes | Lifetime Guarantee',
        ]);
        GuaranteeRule::factory()->for($this->tenant)->lifetime()->create();

        Http::fake(['*' => Http::response(['refill' => '1'])]);

        $this->send('hi');
        $this->send('1');
        $this->send('48220');

        $this->assertStringContainsString('Lifetime', json_encode($this->toCustomer()));
    }

    public function test_an_unmatched_service_is_refused_a_refill(): void
    {
        // No rules configured at all: blocked is the safe default.
        $this->withPanel();
        BotOrder::factory()->for($this->tenant)->create([
            'provider_order_id' => '48220',
            'service_name' => 'Some Service',
        ]);

        Http::fake();

        $this->send('hi');
        $this->send('1');
        $this->send('48220');

        $this->assertStringContainsString('no refill guarantee', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }

    // ---- refill, decided without a rule -----------------------------------

    private function askRefill(string $id): void
    {
        $this->send('hi');
        $this->send('1');
        $this->send($id);
    }

    private function orderNamed(string $name, array $extra = []): BotOrder
    {
        return BotOrder::factory()->for($this->tenant)->create(['provider_order_id' => '48220', 'service_name' => $name] + $extra);
    }

    public function test_a_refill_is_read_from_the_service_name_when_there_is_no_rule(): void
    {
        $this->withPanel();
        $this->orderNamed('IG Followers | 30 Days Refill');
        Http::fake(['*' => Http::response(['refill' => '9001'])]);

        $this->askRefill('48220');

        $this->assertStringContainsString('Refill for *#48220* submitted', json_encode($this->toCustomer()));
        $this->assertStringContainsString('30 days', json_encode($this->toCustomer()));
    }

    public function test_a_no_refill_name_is_refused_without_asking_the_panel(): void
    {
        $this->withPanel();
        $this->orderNamed('IG Followers | No Refill');
        Http::fake();

        $this->askRefill('48220');

        $this->assertStringContainsString('no refill guarantee', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }

    public function test_an_order_older_than_its_guarantee_is_refused(): void
    {
        $this->withPanel();
        $this->orderNamed('IG Followers | 30 Days Refill', ['created_at' => now()->subDays(45)]);
        Http::fake();

        $this->askRefill('48220');

        $this->assertStringContainsString('has ended', json_encode($this->toCustomer()));
        $this->assertStringContainsString('45 days old', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }

    public function test_a_lifetime_name_is_never_too_old(): void
    {
        $this->withPanel();
        $this->orderNamed('IG Likes | Lifetime Refill', ['created_at' => now()->subDays(900)]);
        Http::fake(['*' => Http::response(['refill' => '1'])]);

        $this->askRefill('48220');

        $this->assertStringContainsString('Lifetime', json_encode($this->toCustomer()));
    }

    public function test_a_rule_overrides_what_the_name_says(): void
    {
        $this->withPanel();
        $this->orderNamed('IG Followers | 30 Days Refill');
        GuaranteeRule::factory()->for($this->tenant)->noGuarantee()->create(['keyword' => 'ig followers']);
        Http::fake();

        $this->askRefill('48220');

        $this->assertStringContainsString('no refill guarantee', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }

    public function test_the_resellers_own_refill_wording_beats_the_name(): void
    {
        $this->withPanel();
        $service = \App\Models\BotService::factory()->for($this->tenant)->create(['refill_info' => 'No refill']);
        $this->orderNamed('IG Followers | 30 Days Refill', ['service_id' => $service->id]);
        Http::fake();

        $this->askRefill('48220');

        $this->assertStringContainsString('no refill guarantee', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }

    public function test_a_silent_service_follows_the_default_to_allow(): void
    {
        $this->withPanel();
        BotSettings::save($this->tenant->id, 'support', ['refill' => ['default' => 'allow']]);
        $this->orderNamed('Some Service');
        Http::fake(['*' => Http::response(['refill' => '9'])]);

        $this->askRefill('48220');

        $this->assertStringContainsString('Refill for *#48220* submitted', json_encode($this->toCustomer()));
        $this->assertStringNotContainsString('Guarantee:', json_encode($this->toCustomer()));
    }

    public function test_a_silent_service_can_be_handed_to_the_team(): void
    {
        $this->withPanel();
        BotSettings::save($this->tenant->id, 'support', [
            'refill' => ['default' => 'human'],
            'staff' => ['numbers' => [self::STAFF]],
        ]);
        $this->orderNamed('Some Service');
        Http::fake();

        $this->askRefill('48220');

        $this->assertStringContainsString('asked our team', json_encode($this->toCustomer()));
        $this->assertStringContainsString('needs a decision', json_encode($this->messenger->sent));
        Http::assertNothingSent();
    }

    public function test_an_order_the_bot_did_not_place_is_left_to_the_panel(): void
    {
        $this->withPanel();
        Http::fake(['*' => Http::response(['refill' => '77'])]);

        $this->askRefill('120066');

        $this->assertStringContainsString('Refill for *#120066* submitted', json_encode($this->toCustomer()));
    }

    public function test_the_panels_refusal_is_passed_on_for_an_order_the_bot_did_not_place(): void
    {
        $this->withPanel();
        Http::fake(['*' => Http::response(['error' => 'Refill not available for this service'])]);

        $this->askRefill('120066');

        $this->assertStringContainsString('Refill not available for this service', json_encode($this->toCustomer()));
    }

    public function test_an_unknown_order_can_be_refused_or_handed_to_the_team(): void
    {
        $this->withPanel();
        BotSettings::save($this->tenant->id, 'support', ['refill' => ['unknown_order' => 'refuse']]);
        Http::fake();

        $this->askRefill('120066');

        $this->assertStringContainsString('no refill guarantee', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }

    public function test_turning_automatic_reading_off_leaves_only_the_rules(): void
    {
        $this->withPanel();
        BotSettings::save($this->tenant->id, 'support', ['refill' => ['auto_read' => false]]);
        $this->orderNamed('IG Followers | 30 Days Refill');
        Http::fake();

        $this->askRefill('48220');

        $this->assertStringContainsString('no refill guarantee', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }

    // ---- actions that only notify staff -----------------------------------

    public function test_a_partial_report_needs_no_panel(): void
    {
        $this->withStaff();
        Http::fake();

        $this->send('hi');
        $this->send('4');
        $this->send('48220');

        // Checked against the raw bodies: json_encode escapes the slash.
        $bodies = implode("\n", array_column($this->toCustomer(), 'body'));
        $this->assertStringContainsString('partial / fake completion', $bodies);
        Http::assertNothingSent();
    }

    public function test_it_notifies_staff_of_a_partial_report(): void
    {
        $this->withStaff();

        $this->send('hi');
        $this->send('4');
        $this->send('48220');

        $toStaff = array_filter($this->messenger->sent, fn ($m) => $m['to'] === self::STAFF);
        $this->assertNotEmpty($toStaff);
        $this->assertStringContainsString('48220', json_encode($toStaff));
    }

    /**
     * The old platform answered this with a `wa.me` link to a staff member's
     * personal WhatsApp, which moved the conversation out of the reseller's
     * inbox and left the bot still listening on this number. Now the
     * conversation is claimed instead: a ticket carries the thread and staff
     * answer from the inbox.
     */
    public function test_talk_to_a_human_opens_a_ticket_and_keeps_the_conversation_here(): void
    {
        $this->withStaff();

        $this->send('hi');
        $this->send('5');

        $ticket = Ticket::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_identifier', self::CUSTOMER)
            ->first();

        $this->assertNotNull($ticket, 'asking for a human should open a ticket');
        $this->assertSame('human', $ticket->category);
        $this->assertNotNull($ticket->handed_over_at, 'the ticket should be handed over');

        $this->assertStringNotContainsString('wa.me', $this->lastToCustomer());
        $this->assertStringContainsString('reply here', $this->lastToCustomer());
    }

    // ---- option 8: AI FAQ ------------------------------------------------

    /** A reseller who has paid for AI and stored a key. */
    private function withAi(): void
    {
        Subscription::factory()->for($this->tenant)->active()->create([
            'service_key' => ServiceKey::AiChat,
        ]);

        TenantAi::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'deepseek_api_key_enc' => 'sk-deepseek',
            'status' => 'active',
        ]);
    }

    public function test_the_ai_faq_answers_a_question(): void
    {
        $this->withAi();

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Delivery starts within an hour.']]],
            ]),
        ]);

        $this->send('hi');
        $this->send('8');
        $this->send('how long does delivery take?');

        $this->assertStringContainsString('within an hour', $this->lastToCustomer());
        $this->assertSame(SupportState::AiFaq->value, $this->state());
    }

    /**
     * A reseller without the add-on must not leave a customer on a dead
     * option — they get a person instead, and DeepSeek is never called.
     */
    public function test_the_ai_faq_falls_back_to_a_human_without_the_addon(): void
    {
        Http::fake();

        $this->send('hi');
        $this->send('8');

        Http::assertNothingSent();
        $this->assertNotNull(Ticket::handoffFor($this->tenant->id, self::CUSTOMER));
    }

    /**
     * DeepSeek being down mid-conversation is the case most likely to strand
     * someone: they have already typed a question and are owed a person.
     */
    public function test_a_failed_answer_hands_the_customer_to_a_human(): void
    {
        $this->withAi();

        Http::fake(['api.deepseek.com/*' => Http::response([], 500)]);

        $this->send('hi');
        $this->send('8');
        $this->send('are you there?');

        $this->assertNotNull(Ticket::handoffFor($this->tenant->id, self::CUSTOMER));
    }

    /** The escape hatch: *0* must always reach the menu, mid-AI or not. */
    public function test_the_menu_word_escapes_the_ai_faq(): void
    {
        $this->withAi();

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'An answer.']]],
            ]),
        ]);

        $this->send('hi');
        $this->send('8');
        $this->send('a question');
        $this->assertSame(SupportState::AiFaq->value, $this->state());

        $this->send('0');

        $this->assertSame(SupportState::Menu->value, $this->state());
    }

    public function test_talk_to_a_human_still_answers_with_no_staff_configured(): void
    {
        $this->send('hi');
        $this->send('5');

        $this->assertStringContainsString('reply here', $this->lastToCustomer());
        $this->assertNotNull(Ticket::handoffFor($this->tenant->id, self::CUSTOMER));
    }

    /**
     * The whole point of the handoff: once a person owns the conversation the
     * bot must not answer alongside them.
     */
    public function test_the_bot_stays_silent_once_a_person_has_taken_over(): void
    {
        $this->send('hi');
        $this->send('5');

        $before = count($this->toCustomer());

        $this->send('are you there?');

        $this->assertCount($before, $this->toCustomer(), 'the bot should not reply during a handoff');
    }

    /** What the customer says while waiting has to reach the person reading it. */
    public function test_messages_during_a_handoff_are_recorded_on_the_ticket(): void
    {
        $this->send('hi');
        $this->send('5');
        $this->send('my order 123 never arrived');

        $ticket = Ticket::handoffFor($this->tenant->id, self::CUSTOMER);

        $this->assertNotNull($ticket);
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'sender' => 'customer',
            'message' => 'my order 123 never arrived',
        ]);
    }

    /** Asking twice is still one conversation, not two threads to answer. */
    public function test_asking_for_a_human_twice_reuses_the_same_ticket(): void
    {
        $this->send('hi');
        $this->send('5');

        $first = Ticket::handoffFor($this->tenant->id, self::CUSTOMER);

        // Handing back lets the menu answer again, so 5 can be pressed twice.
        $first->returnToBot();

        $this->send('hi');
        $this->send('5');

        $this->assertSame(1, Ticket::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_identifier', self::CUSTOMER)
            ->count());
    }

    public function test_the_bot_answers_again_once_the_conversation_is_handed_back(): void
    {
        $this->send('hi');
        $this->send('5');

        Ticket::handoffFor($this->tenant->id, self::CUSTOMER)->returnToBot();

        $before = count($this->toCustomer());
        $this->send('hi');

        $this->assertGreaterThan($before, count($this->toCustomer()));
    }

    public function test_a_top_up_issue_explains_what_to_send(): void
    {
        $this->send('hi');
        $this->send('7');

        $this->assertStringContainsString('payment reference', $this->lastToCustomer());
    }

    // ---- isolation --------------------------------------------------------

    public function test_it_does_not_read_another_tenants_order_for_the_guarantee_check(): void
    {
        $this->withPanel();

        // The order — and so the service name the guarantee is judged on —
        // belongs to someone else.
        $other = Tenant::factory()->create();
        BotOrder::factory()->for($other)->create([
            'provider_order_id' => '48220',
            'service_name' => 'IG Followers | 30 Days Refill',
        ]);
        GuaranteeRule::factory()->for($this->tenant)->create([
            'keyword' => '30 days',
            'refill_days' => 30,
        ]);
        // Not ours, so unknown to us: refused here, to show the other
        // tenant's service name was not borrowed to grant it.
        BotSettings::save($this->tenant->id, 'support', ['refill' => ['unknown_order' => 'refuse']]);

        Http::fake();

        $this->send('hi');
        $this->send('1');
        $this->send('48220');

        $this->assertStringContainsString('no refill guarantee', json_encode($this->toCustomer()));
        Http::assertNothingSent();
    }
}
