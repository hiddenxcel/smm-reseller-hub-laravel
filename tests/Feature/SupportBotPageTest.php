<?php

namespace Tests\Feature;

use App\Models\BotMessage;
use App\Models\GuaranteeRule;
use App\Models\ResponseTemplate;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Bots\BotSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The support bot's console: overview, guarantee rules, templates, settings —
 * and the two screens where a person actually answers a customer.
 *
 * The care goes into the handoff. A reply that reaches the customer but leaves
 * the bot talking, or a bot that resumes because a row expired, both produce
 * the same failure: two voices answering one person.
 */
class SupportBotPageTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '255700000001';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
    }

    private function connectNumber(string $bot = 'support'): TenantWhatsApp
    {
        return TenantWhatsApp::factory()->for($this->tenant)->create([
            'bot_type' => $bot,
            'status' => 'active',
            'display_number' => '+255700000009',
            'cloud_api_token_enc' => 'token',
        ]);
    }

    /** BotMessage has no factory; the log is written by hand everywhere. */
    private function logInbound(string $phone, string $message = 'hello', ?string $at = null): void
    {
        $row = BotMessage::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'customer_phone' => $phone,
            'direction' => 'in',
            'message' => $message,
            'bot_type' => 'support',
        ]);

        if ($at !== null) {
            $row->forceFill(['created_at' => $at])->save();
        }
    }

    /** Meta's window is open, so a reply is allowed to go out. */
    private function withOpenWindow(): void
    {
        $this->connectNumber();
        $this->logInbound(self::CUSTOMER);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    }

    // ---- the page itself -------------------------------------------------

    public function test_the_overview_reports_what_is_missing(): void
    {
        $this->actingAs($this->tenant)
            ->get(route('support-bot'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SupportBot/Index')
                ->where('overview.checks.whatsapp', false)
                ->where('overview.checks.rules', false));
    }

    public function test_the_overview_counts_customers_waiting_for_a_person(): void
    {
        Ticket::factory()->for($this->tenant)->create(['handed_over_at' => now()]);
        Ticket::factory()->for($this->tenant)->create(['handed_over_at' => null]);

        // Resolved tickets are not waiting on anybody, handed over or not.
        Ticket::factory()->for($this->tenant)->resolved()->create(['handed_over_at' => now()]);

        $this->actingAs($this->tenant)
            ->get(route('support-bot'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('overview.awaitingHuman', 1));
    }

    public function test_the_overview_shows_which_menu_options_are_switched_off(): void
    {
        BotSettings::save($this->tenant->id, 'support', [
            'commands' => ['refill' => false, 'status' => true, 'cancel' => true, 'speedup' => false],
        ]);

        $this->actingAs($this->tenant)
            ->get(route('support-bot'))
            ->assertInertia(function (AssertableInertia $page) {
                $menu = collect($page->toArray()['props']['overview']['menu']);

                $this->assertFalse($menu->firstWhere('toggle', 'refill')['enabled']);
                $this->assertTrue($menu->firstWhere('toggle', 'status')['enabled']);

                // "Talk to a human" has no toggle and must always be offered.
                $this->assertTrue($menu->firstWhere('value', '5')['enabled']);
            });
    }

    public function test_an_unknown_tab_is_not_a_page(): void
    {
        $this->actingAs($this->tenant)->get('/support-bot/nonsense')->assertNotFound();
    }

    // ---- guarantee rules -------------------------------------------------

    public function test_a_guarantee_rule_can_be_added(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('support-bot.rules.store'), [
                'type' => 'guarantee',
                'keyword' => 'instagram followers',
                'refillDays' => 30,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('guarantee_rules', [
            'tenant_id' => $this->tenant->id,
            'keyword' => 'instagram followers',
            'refill_days' => 30,
        ]);
    }

    /** 0 is lifetime, not "no days" — the floor must let it through. */
    public function test_a_lifetime_rule_is_accepted(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('support-bot.rules.store'), [
                'type' => 'guarantee',
                'keyword' => 'premium',
                'refillDays' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('guarantee_rules', ['keyword' => 'premium', 'refill_days' => 0]);
    }

    public function test_a_rule_cannot_be_attached_to_another_tenants_panel(): void
    {
        $other = Tenant::factory()->create();
        $panel = \App\Models\TenantPanel::factory()->for($other)->create();

        $this->actingAs($this->tenant)
            ->post(route('support-bot.rules.store'), [
                'type' => 'guarantee',
                'keyword' => 'anything',
                'refillDays' => 30,
                'panelId' => $panel->id,
            ])
            ->assertSessionHasErrors('panelId');
    }

    public function test_another_tenants_rule_cannot_be_deleted(): void
    {
        $other = Tenant::factory()->create();
        $rule = GuaranteeRule::factory()->for($other)->create();

        $this->actingAs($this->tenant)
            ->delete(route('support-bot.rules.destroy', $rule->id))
            ->assertNotFound();

        $this->assertDatabaseHas('guarantee_rules', ['id' => $rule->id]);
    }

    // ---- templates -------------------------------------------------------

    public function test_a_template_override_is_saved_and_reported_as_custom(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('support-bot.templates.update'), [
                'key' => 'SUPPORT_MENU',
                'lang' => 'en',
                'content' => 'Karibu kwenye huduma yetu.',
            ])
            ->assertRedirect();

        $this->actingAs($this->tenant)
            ->get(route('support-bot', 'templates'))
            ->assertInertia(function (AssertableInertia $page) {
                $row = collect($page->toArray()['props']['templates']['rows'])
                    ->firstWhere('key', 'SUPPORT_MENU');

                $this->assertTrue($row['custom']);
                $this->assertSame('Karibu kwenye huduma yetu.', $row['content']);
            });
    }

    /**
     * Clearing the box means "use the built-in wording", not "send nothing" —
     * so it deletes the override rather than storing a blank.
     */
    public function test_clearing_a_template_removes_the_override(): void
    {
        ResponseTemplate::create([
            'tenant_id' => $this->tenant->id,
            'template_key' => 'SUPPORT_MENU',
            'lang' => 'en',
            'content' => 'Something',
        ]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.templates.update'), [
                'key' => 'SUPPORT_MENU',
                'lang' => 'en',
                'content' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('response_templates', [
            'tenant_id' => $this->tenant->id,
            'template_key' => 'SUPPORT_MENU',
        ]);
    }

    /**
     * The bots ship five locales but the column's CHECK constraint allowed
     * three, so this would have failed at the database for a language the bot
     * already speaks.
     */
    public function test_a_template_can_be_saved_in_every_language_the_bot_speaks(): void
    {
        foreach (['en', 'fr', 'sw', 'tr', 'hi'] as $lang) {
            $this->actingAs($this->tenant)
                ->post(route('support-bot.templates.update'), [
                    'key' => 'SUPPORT_MENU',
                    'lang' => $lang,
                    'content' => "menu in {$lang}",
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(5, ResponseTemplate::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_only_keys_the_bot_actually_sends_are_accepted(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('support-bot.templates.update'), [
                'key' => 'NOT_A_REAL_KEY',
                'lang' => 'en',
                'content' => 'text',
            ])
            ->assertSessionHasErrors('key');
    }

    // ---- settings --------------------------------------------------------

    /**
     * `shop` also holds gateway ids and links this form never shows. Saving
     * settings must not take them with it.
     */
    public function test_saving_settings_keeps_keys_the_form_never_showed(): void
    {
        BotSettings::save($this->tenant->id, 'support', [
            'shop' => ['currency' => 'TZS', 'group_url' => 'https://chat.example'],
        ]);

        $this->actingAs($this->tenant)->post(route('support-bot.settings'), [
            'commands' => ['refill' => true, 'status' => true, 'cancel' => false, 'speedup' => false],
            'spam' => [
                'enabled' => true,
                'repeat_threshold' => 5,
                'window_minutes' => 10,
                'disable_minutes' => 60,
            ],
            'staff' => ['255700000099'],
            'lang' => 'sw',
        ])->assertRedirect();

        $settings = BotSettings::for($this->tenant->id, 'support');

        $this->assertSame('TZS', Arr::get($settings, 'shop.currency'));
        $this->assertSame('https://chat.example', Arr::get($settings, 'shop.group_url'));
        $this->assertSame('sw', Arr::get($settings, 'shop.lang'));
        $this->assertFalse(Arr::get($settings, 'commands.cancel'));
    }

    /** A threshold of 1 would block a customer for saying hello twice. */
    public function test_an_absurd_spam_threshold_is_rejected(): void
    {
        $this->actingAs($this->tenant)->post(route('support-bot.settings'), [
            'commands' => ['refill' => true, 'status' => true, 'cancel' => true, 'speedup' => false],
            'spam' => [
                'enabled' => true,
                'repeat_threshold' => 1,
                'window_minutes' => 5,
                'disable_minutes' => 60,
            ],
            'lang' => 'en',
        ])->assertSessionHasErrors('spam.repeat_threshold');
    }

    public function test_test_numbers_save_without_touching_the_other_settings(): void
    {
        BotSettings::save($this->tenant->id, 'support', [
            'staff' => ['numbers' => ['255700000099']],
        ]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.test-numbers'), ['testNumbers' => ['255700000001']])
            ->assertRedirect();

        $settings = BotSettings::for($this->tenant->id, 'support');

        $this->assertSame(['255700000001'], Arr::get($settings, 'shop.test_numbers'));
        $this->assertSame(['255700000099'], Arr::get($settings, 'staff.numbers'));
    }

    // ---- inbox -----------------------------------------------------------

    public function test_the_inbox_flags_conversations_waiting_for_a_person(): void
    {
        $this->logInbound(self::CUSTOMER);
        Ticket::factory()->for($this->tenant)->create([
            'customer_identifier' => self::CUSTOMER,
            'handed_over_at' => now(),
        ]);

        $this->actingAs($this->tenant)
            ->get(route('support-bot.inbox'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SupportBot/Inbox')
                ->where('conversations.0.awaitingHuman', true));
    }

    public function test_the_thread_merges_bot_traffic_and_staff_replies_in_order(): void
    {
        $this->logInbound(self::CUSTOMER, 'my order is late');

        $ticket = Ticket::factory()->for($this->tenant)->create([
            'customer_identifier' => self::CUSTOMER,
            'handed_over_at' => now(),
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender' => 'staff',
            'message' => 'Looking into it now',
        ]);

        $this->actingAs($this->tenant)
            ->get(route('support-bot.inbox', ['phone' => self::CUSTOMER]))
            ->assertInertia(function (AssertableInertia $page) {
                $messages = collect($page->toArray()['props']['thread']['messages']);

                $this->assertSame('customer', $messages->first()['sender']);
                $this->assertSame('staff', $messages->last()['sender']);
                $this->assertSame('Looking into it now', $messages->last()['message']);
            });
    }

    public function test_a_reply_is_sent_and_recorded(): void
    {
        $this->withOpenWindow();

        $ticket = Ticket::factory()->for($this->tenant)->create([
            'customer_identifier' => self::CUSTOMER,
            'handed_over_at' => now(),
        ]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.inbox.reply'), [
                'phone' => self::CUSTOMER,
                'message' => 'Sorry about that — refilling it now.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'sender' => 'staff',
            'message' => 'Sorry about that — refilling it now.',
        ]);
    }

    /** Staff answering a conversation the bot still owns claims it. */
    public function test_replying_takes_the_conversation_off_the_bot(): void
    {
        $this->withOpenWindow();

        $this->actingAs($this->tenant)
            ->post(route('support-bot.inbox.reply'), [
                'phone' => self::CUSTOMER,
                'message' => 'Hello, this is Amina.',
            ])
            ->assertRedirect();

        $this->assertNotNull(Ticket::handoffFor($this->tenant->id, self::CUSTOMER));
    }

    /**
     * Meta refuses free-form replies more than 24 hours after the customer's
     * last message. Failing here beats failing at the API with no explanation.
     */
    public function test_a_reply_outside_the_24_hour_window_is_refused(): void
    {
        $this->connectNumber();
        $this->logInbound(self::CUSTOMER, 'hello', now()->subDays(3)->toDateTimeString());

        Ticket::factory()->for($this->tenant)->create([
            'customer_identifier' => self::CUSTOMER,
            'handed_over_at' => now(),
        ]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.inbox.reply'), [
                'phone' => self::CUSTOMER,
                'message' => 'Still there?',
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('ticket_messages', 0);
    }

    /** A thread row claiming staff replied, when Meta refused, is worse than none. */
    public function test_nothing_is_recorded_when_the_send_fails(): void
    {
        $this->connectNumber();
        $this->logInbound(self::CUSTOMER);
        Http::fake(['*' => Http::response(['error' => 'bad token'], 401)]);

        Ticket::factory()->for($this->tenant)->create([
            'customer_identifier' => self::CUSTOMER,
            'handed_over_at' => now(),
        ]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.inbox.reply'), [
                'phone' => self::CUSTOMER,
                'message' => 'Are you there?',
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('ticket_messages', 0);
    }

    public function test_a_conversation_can_be_handed_back_to_the_bot(): void
    {
        Ticket::factory()->for($this->tenant)->create([
            'customer_identifier' => self::CUSTOMER,
            'handed_over_at' => now(),
        ]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.inbox.return'), ['phone' => self::CUSTOMER])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull(Ticket::handoffFor($this->tenant->id, self::CUSTOMER));
    }

    // ---- tickets ---------------------------------------------------------

    public function test_tickets_waiting_for_a_person_sort_first(): void
    {
        Ticket::factory()->for($this->tenant)->create([
            'subject' => 'Ordinary',
            'handed_over_at' => null,
        ]);

        Ticket::factory()->for($this->tenant)->create([
            'subject' => 'Waiting',
            'handed_over_at' => now(),
        ]);

        $this->actingAs($this->tenant)
            ->get(route('support-bot.tickets'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SupportBot/Tickets')
                ->where('rows.0.subject', 'Waiting'));
    }

    public function test_tickets_can_be_filtered_by_who_opened_them(): void
    {
        Ticket::factory()->for($this->tenant)->create(['subject' => 'Asked for help']);
        Ticket::factory()->for($this->tenant)->fromAi()->create(['subject' => 'Bot logged it']);

        $this->actingAs($this->tenant)
            ->get(route('support-bot.tickets', ['category' => 'ai']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('rows.0.subject', 'Bot logged it'));
    }

    public function test_another_tenants_ticket_cannot_be_opened(): void
    {
        $other = Tenant::factory()->create();
        $ticket = Ticket::factory()->for($other)->create();

        $this->actingAs($this->tenant)
            ->get(route('support-bot.tickets.show', $ticket->id))
            ->assertNotFound();
    }

    public function test_another_tenants_ticket_cannot_be_replied_to(): void
    {
        $other = Tenant::factory()->create();
        $ticket = Ticket::factory()->for($other)->create();

        $this->actingAs($this->tenant)
            ->post(route('support-bot.tickets.reply', $ticket->id), ['message' => 'hello'])
            ->assertNotFound();
    }

    /**
     * Resolving and handing back are separate decisions: staff often answer
     * and keep the conversation while the customer reads the reply.
     */
    public function test_resolving_a_ticket_does_not_hand_it_back_to_the_bot(): void
    {
        $ticket = Ticket::factory()->for($this->tenant)->create(['handed_over_at' => now()]);

        $this->actingAs($this->tenant)
            ->patch(route('support-bot.tickets.update', $ticket->id), ['status' => 'resolved'])
            ->assertRedirect();

        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->handed_over_at);
    }

    public function test_a_ticket_can_be_handed_back_without_resolving_it(): void
    {
        $ticket = Ticket::factory()->for($this->tenant)->create(['handed_over_at' => now()]);

        $this->actingAs($this->tenant)
            ->patch(route('support-bot.tickets.update', $ticket->id), ['handedOver' => false])
            ->assertRedirect();

        $this->assertNull($ticket->fresh()->handed_over_at);
        $this->assertSame('open', $ticket->fresh()->status);
    }

    /** A customer writing back to a closed ticket is not a new conversation. */
    public function test_replying_to_a_resolved_ticket_reopens_it(): void
    {
        $this->withOpenWindow();

        $ticket = Ticket::factory()->for($this->tenant)->resolved()->create([
            'customer_identifier' => self::CUSTOMER,
        ]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.tickets.reply', $ticket->id), ['message' => 'One more thing'])
            ->assertRedirect();

        $this->assertSame('open', $ticket->fresh()->status);
    }

    // ---- isolation -------------------------------------------------------

    public function test_the_inbox_does_not_show_another_tenants_conversations(): void
    {
        $other = Tenant::factory()->create();

        BotMessage::withoutTenantScope()->create([
            'tenant_id' => $other->id,
            'customer_phone' => '255799999999',
            'direction' => 'in',
            'message' => 'not yours',
            'bot_type' => 'support',
        ]);

        $this->actingAs($this->tenant)
            ->get(route('support-bot.inbox'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 0));
    }

    public function test_the_inbox_does_not_show_order_bot_conversations(): void
    {
        BotMessage::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'customer_phone' => self::CUSTOMER,
            'direction' => 'in',
            'message' => 'I want followers',
            'bot_type' => 'order',
        ]);

        $this->actingAs($this->tenant)
            ->get(route('support-bot.inbox'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 0));
    }

    public function test_the_pages_need_a_login(): void
    {
        $this->get(route('support-bot'))->assertRedirect(route('login'));
        $this->get(route('support-bot.inbox'))->assertRedirect(route('login'));
        $this->get(route('support-bot.tickets'))->assertRedirect(route('login'));
    }
}
