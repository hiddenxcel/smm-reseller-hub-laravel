<?php

namespace Tests\Feature;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledge;
use App\Models\AssistantMessage;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The assistant's console.
 *
 * Its reason to exist is the loop: a visitor asks something the assistant
 * cannot answer, that question appears here in their own words, somebody
 * writes an answer, and the next person to ask gets it. Both halves of that
 * loop are tested — the list surfacing the misses, and the answer taking
 * effect without a deploy.
 */
class AdminAssistantTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The seed migration fills this table on every environment, which is
        // the point of it — production must not come up with an assistant
        // that knows nothing. These tests assert on rows they create
        // themselves, so they start from an empty table rather than counting
        // around two dozen shipped answers.
        AssistantKnowledge::query()->delete();
        AssistantKnowledge::forget();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->actingAs($this->admin, 'superadmin');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'topic' => 'order_bot',
            'question' => 'What is Order Bot?',
            'question_sw' => 'Order Bot ni nini?',
            'answer' => 'It turns your WhatsApp number into a shop.',
            'answer_sw' => 'Inageuza namba yako ya WhatsApp kuwa duka.',
            'cta_label' => 'See pricing',
            'cta_url' => '/pricing',
            'keywords' => 'order bot, selling on whatsapp',
            'status' => 'active',
            'sort_order' => 10,
            ...$overrides,
        ];
    }

    /** A visitor's turn and the reply it got, as the widget would record them. */
    private function exchange(string $question, string $outcome): AssistantConversation
    {
        $conversation = AssistantConversation::create([
            'session_token' => fake()->uuid(),
            'page' => '/pricing',
        ]);

        $conversation->record('user', $question);
        $conversation->record('assistant', 'Some reply', $outcome);

        return $conversation;
    }

    public function test_the_console_lists_the_knowledge_base(): void
    {
        AssistantKnowledge::create($this->payload());

        $this->get('/hx-control/assistant')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assistant/Index')
                ->has('knowledge', 1)
                ->where('knowledge.0.question', 'What is Order Bot?')
                ->has('topics'),
            );
    }

    public function test_questions_it_could_not_answer_are_surfaced_for_writing(): void
    {
        $this->exchange('Do you support TikTok Shop?', AssistantMessage::FALLBACK);
        $this->exchange('Do you support TikTok Shop?', AssistantMessage::FALLBACK);
        $this->exchange('What is Order Bot?', AssistantMessage::KNOWLEDGE);

        $this->get('/hx-control/assistant')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Only the miss. A question that was answered is not work.
                ->has('unanswered', 1)
                ->where('unanswered.0.question', 'do you support tiktok shop?')
                ->where('unanswered.0.count', 2)
                ->where('stats.unanswered', 2)
                ->where('stats.fromKnowledge', 1),
            );
    }

    public function test_a_question_is_counted_once_however_long_the_chat_ran(): void
    {
        $conversation = AssistantConversation::create([
            'session_token' => fake()->uuid(),
        ]);

        // One question, then several more turns after it. Joining every later
        // reply rather than the next one would count this question four times
        // and let the longest conversation dominate the list.
        $conversation->record('user', 'Do you support TikTok Shop?');
        $conversation->record('assistant', 'No idea', AssistantMessage::FALLBACK);
        $conversation->record('user', 'And Instagram?');
        $conversation->record('assistant', 'Yes', AssistantMessage::KNOWLEDGE);
        $conversation->record('user', 'And YouTube?');
        $conversation->record('assistant', 'Yes', AssistantMessage::KNOWLEDGE);

        $this->get('/hx-control/assistant')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('unanswered.0.count', 1)
                ->has('unanswered', 1),
            );
    }

    public function test_writing_an_answer_makes_the_assistant_use_it(): void
    {
        $this->post('/hx-control/assistant/knowledge', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('assistant_knowledge', ['question' => 'What is Order Bot?']);

        // The loop that justifies the whole screen: written here, live on the
        // website immediately, with no deploy in between.
        $this->post('/assistant/ask', ['message' => 'What is Order Bot?'])
            ->assertOk()
            ->assertJsonPath('answered_by', AssistantMessage::KNOWLEDGE)
            ->assertJsonPath('reply', 'It turns your WhatsApp number into a shop.');
    }

    public function test_an_answer_can_be_edited_and_removed(): void
    {
        $entry = AssistantKnowledge::create($this->payload());

        $this->patch("/hx-control/assistant/knowledge/{$entry->id}", $this->payload([
            'answer' => 'It sells for you on WhatsApp.',
        ]))->assertRedirect();

        $this->assertSame('It sells for you on WhatsApp.', $entry->fresh()->answer);

        $this->delete("/hx-control/assistant/knowledge/{$entry->id}")->assertRedirect();

        $this->assertDatabaseMissing('assistant_knowledge', ['id' => $entry->id]);
    }

    public function test_a_link_off_the_site_is_refused(): void
    {
        // A CTA is a place we send people. An absolute URL here is a way to
        // send them somewhere that is not ours.
        $this->post('/hx-control/assistant/knowledge', $this->payload([
            'cta_url' => 'https://elsewhere.example/offer',
        ]))->assertSessionHasErrors('cta_url');

        $this->assertDatabaseCount('assistant_knowledge', 0);
    }

    public function test_a_conversation_can_be_read_back(): void
    {
        $conversation = $this->exchange('Do you support TikTok Shop?', AssistantMessage::FALLBACK);

        $this->get("/hx-control/assistant/conversations/{$conversation->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assistant/Conversation')
                ->has('conversation.messages', 2)
                ->where('conversation.messages.0.content', 'Do you support TikTok Shop?')
                ->where('conversation.messages.1.answeredBy', AssistantMessage::FALLBACK),
            );
    }

    public function test_leads_are_listed_with_their_number(): void
    {
        $conversation = $this->exchange('Can someone call me?', AssistantMessage::FALLBACK);

        $conversation->update([
            'lead_name' => 'Asha',
            'lead_phone' => '255700000000',
            'escalated_at' => now(),
        ]);

        $this->get('/hx-control/assistant')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('leads', 1)
                ->where('leads.0.name', 'Asha')
                ->where('stats.leads', 1),
            );
    }

    public function test_a_reseller_cannot_reach_the_console(): void
    {
        $this->post('/hx-control/logout');

        $this->actingAs(Tenant::factory()->create(), 'tenant')
            ->get('/hx-control/assistant')
            ->assertRedirect();
    }

    public function test_a_signed_out_visitor_cannot_reach_the_console(): void
    {
        $this->post('/hx-control/logout');

        $this->get('/hx-control/assistant')->assertRedirect();
        $this->post('/hx-control/assistant/knowledge', $this->payload())->assertRedirect();
    }
}
