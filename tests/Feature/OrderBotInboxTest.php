<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The order bot's inbox.
 *
 * Two things carry the risk here: isolation, because an inbox that leaks shows
 * another reseller their customers' actual words; and the conversation list's
 * preview, which is built from a subquery that is easy to get subtly wrong —
 * a plain GROUP BY returns the newest id beside some other row's text.
 */
class OrderBotInboxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function message(
        Tenant $tenant,
        string $phone,
        string $body,
        string $direction = 'in',
        string $bot = 'order',
    ): BotMessage {
        return BotMessage::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $phone,
            'direction' => $direction,
            'message' => $body,
            'bot_type' => $bot,
        ]);
    }

    public function test_it_lists_one_row_per_customer(): void
    {
        $this->message($this->tenant, '255700000001', 'first');
        $this->message($this->tenant, '255700000001', 'second');
        $this->message($this->tenant, '255700000002', 'from someone else');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('OrderBot/Inbox')
                ->has('conversations', 2),
            );
    }

    /**
     * The preview must be the newest message's own text — this is the exact
     * pairing a naive GROUP BY gets wrong.
     */
    public function test_the_preview_is_the_newest_message(): void
    {
        $this->message($this->tenant, '255700000001', 'older message');
        $this->message($this->tenant, '255700000001', 'newest message', 'out');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('conversations.0.lastMessage', 'newest message')
                ->where('conversations.0.lastDirection', 'out'),
            );
    }

    public function test_conversations_are_ordered_most_recent_first(): void
    {
        $this->message($this->tenant, '255700000001', 'older');
        $this->message($this->tenant, '255700000002', 'newer');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('conversations.0.phone', '255700000002')
                ->where('conversations.1.phone', '255700000001'),
            );
    }

    public function test_it_shows_the_customer_name_when_there_is_one(): void
    {
        BotCustomer::factory()->for($this->tenant)->create([
            'phone' => '255700000001',
            'name' => 'Asha',
        ]);

        $this->message($this->tenant, '255700000001', 'hello');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('conversations.0.name', 'Asha'),
            );
    }

    /** The support bot has its own inbox; its messages must not appear here. */
    public function test_it_excludes_the_other_bots_messages(): void
    {
        $this->message($this->tenant, '255700000001', 'about an order');
        $this->message($this->tenant, '255700000002', 'a support question', 'in', 'support');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.phone', '255700000001'),
            );
    }

    public function test_it_does_not_leak_another_tenants_conversations(): void
    {
        $other = Tenant::factory()->create();
        $this->message($other, '255700000009', 'not yours');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 0));
    }

    public function test_the_thread_is_oldest_first(): void
    {
        $this->message($this->tenant, '255700000001', 'first');
        $this->message($this->tenant, '255700000001', 'then this', 'out');
        $this->message($this->tenant, '255700000001', 'and last');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox', ['phone' => '255700000001']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('thread.messages', 3)
                ->where('thread.messages.0.message', 'first')
                ->where('thread.messages.2.message', 'and last'),
            );
    }

    /** The list alone is the common case; the thread query must not run. */
    public function test_no_thread_is_sent_without_a_phone(): void
    {
        $this->message($this->tenant, '255700000001', 'hello');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('thread', null));
    }

    public function test_a_thread_cannot_read_another_tenants_messages(): void
    {
        $other = Tenant::factory()->create();
        $this->message($other, '255700000009', 'private');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox', ['phone' => '255700000009']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('thread.messages', 0));
    }

    /** A thread must not pull in the support bot's half of the conversation. */
    public function test_a_thread_excludes_the_other_bot(): void
    {
        $this->message($this->tenant, '255700000001', 'order side');
        $this->message($this->tenant, '255700000001', 'support side', 'in', 'support');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox', ['phone' => '255700000001']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('thread.messages', 1)
                ->where('thread.messages.0.message', 'order side'),
            );
    }

    public function test_conversations_can_be_searched_by_message(): void
    {
        $this->message($this->tenant, '255700000001', 'Instagram followers');
        $this->message($this->tenant, '255700000002', 'TikTok views');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox', ['q' => 'instagram']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.phone', '255700000001'),
            );
    }

    public function test_conversations_can_be_searched_by_name(): void
    {
        BotCustomer::factory()->for($this->tenant)->create([
            'phone' => '255700000001',
            'name' => 'Asha',
        ]);

        $this->message($this->tenant, '255700000001', 'hello');
        $this->message($this->tenant, '255700000002', 'hello too');

        $this->actingAs($this->tenant)
            ->get(route('order-bot.inbox', ['q' => 'asha']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.name', 'Asha'),
            );
    }

    public function test_it_requires_authentication(): void
    {
        $this->get(route('order-bot.inbox'))->assertRedirect(route('login'));
    }

    /** `/order-bot/inbox` must not be swallowed by the `{tab}` route. */
    public function test_the_inbox_url_is_not_read_as_a_tab(): void
    {
        $this->actingAs($this->tenant)
            ->get('/order-bot/inbox')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('OrderBot/Inbox'));
    }
}
