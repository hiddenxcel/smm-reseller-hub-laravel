<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotService;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantAi;
use App\Services\Ai\AiAnswers;
use App\Services\Ai\ShopContext;
use App\Services\Bots\Order\OrderBotHandler;
use App\Services\Bots\Order\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * The AI add-on: a customer asks a question and the shop's own assistant
 * answers it.
 *
 * Three risks shape these tests. The reseller is billed by DeepSeek for every
 * answer on their own key, so nothing may call it that they have not paid for
 * and switched on. The assistant must never invent a price, because a customer
 * quoted one will hold the shop to it. And a customer must always be able to
 * get out — an AI that cannot be escaped is worse than no AI.
 */
class AiSupportTest extends TestCase
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

        BotCustomer::factory()->for($this->tenant)->create([
            'phone' => self::CUSTOMER,
            'balance' => '0.00',
        ]);

        $this->messenger = new FakeBotMessenger;
    }

    private function send(string $text): void
    {
        (new OrderBotHandler($this->tenant, $this->messenger))->handle(self::CUSTOMER, $text);
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

    /** A reseller who has paid for AI and stored a key. */
    private function withAi(string $key = 'sk-deepseek'): TenantAi
    {
        Subscription::factory()->for($this->tenant)->active()->create([
            'service_key' => ServiceKey::AiChat,
        ]);

        return TenantAi::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'deepseek_api_key_enc' => $key,
            'status' => 'active',
        ]);
    }

    private function fakeDeepSeekSays(string $answer): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $answer]]],
            ]),
        ]);
    }

    /** Walk the customer from the menu into the AI conversation. */
    private function openAiChat(): void
    {
        $this->send('hi');
        $this->send('main:support');
    }

    // ---- the gate --------------------------------------------------------

    /**
     * The whole gate in one test: DeepSeek is never called for a reseller who
     * has not paid for the add-on, however complete the rest of their setup.
     */
    public function test_a_reseller_without_the_addon_never_reaches_deepseek(): void
    {
        Http::fake();

        TenantAi::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'deepseek_api_key_enc' => 'sk-deepseek',
            'status' => 'active',
        ]);

        $this->openAiChat();

        Http::assertNothingSent();
        $this->assertNotSame(OrderState::AiChat->value, $this->state());
    }

    public function test_a_reseller_with_no_key_never_reaches_deepseek(): void
    {
        Http::fake();

        Subscription::factory()->for($this->tenant)->active()->create([
            'service_key' => ServiceKey::AiChat,
        ]);

        $this->openAiChat();

        Http::assertNothingSent();
        $this->assertNotSame(OrderState::AiChat->value, $this->state());
    }

    /** Paused is a deliberate choice, and it must hold. */
    public function test_a_paused_ai_never_reaches_deepseek(): void
    {
        Http::fake();

        $this->withAi()->update(['status' => 'inactive']);

        $this->openAiChat();

        Http::assertNothingSent();
    }

    public function test_a_reseller_without_ai_still_gets_a_helpful_reply(): void
    {
        Http::fake();

        $this->openAiChat();

        // Not silence, and not an error: the customer asked for help and is
        // told how to get it.
        $this->assertNotEmpty($this->messenger->lastBody());
    }

    // ---- answering -------------------------------------------------------

    public function test_a_question_is_answered_and_the_answer_is_sent(): void
    {
        $this->withAi();
        $this->fakeDeepSeekSays('IG Followers are 2,000 TZS per 1,000.');

        $this->openAiChat();
        $this->send('how much are IG followers?');

        $this->assertSame('IG Followers are 2,000 TZS per 1,000.', $this->messenger->lastBody());
        $this->assertSame(OrderState::AiChat->value, $this->state());
    }

    /** The reseller's key, not ours, and it must reach DeepSeek as a bearer token. */
    public function test_the_resellers_own_key_is_what_is_used(): void
    {
        $this->withAi('sk-this-resellers-key');
        $this->fakeDeepSeekSays('Sure.');

        $this->openAiChat();
        $this->send('hello?');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-this-resellers-key'));
    }

    /**
     * A follow-up like "and for TikTok?" only makes sense with what came
     * before it.
     */
    public function test_earlier_turns_are_carried_into_the_next_question(): void
    {
        $this->withAi();
        $this->fakeDeepSeekSays('2,000 TZS per 1,000.');

        $this->openAiChat();
        $this->send('how much are IG followers?');
        $this->send('and for TikTok?');

        Http::assertSent(function ($request) {
            $contents = collect($request['messages'])->pluck('content')->implode(' ');

            return str_contains($contents, 'how much are IG followers?');
        });
    }

    /**
     * The history is re-sent with every question and the reseller pays for
     * those tokens each time, so it cannot grow without bound.
     */
    public function test_the_carried_history_is_capped(): void
    {
        $this->withAi();
        $this->fakeDeepSeekSays('Yes.');

        $this->openAiChat();

        foreach (range(1, 12) as $i) {
            $this->send("question {$i}");
        }

        // Four turns of question-and-answer, so eight entries at most.
        $this->assertLessThanOrEqual(8, count($this->context()['history'] ?? []));
    }

    // ---- when it fails ---------------------------------------------------

    /**
     * DeepSeek being down must not leave a customer talking to nothing. They
     * are told plainly and put back where they can still buy.
     */
    public function test_a_deepseek_failure_returns_the_customer_to_the_menu(): void
    {
        $this->withAi();
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'nope']], 500)]);

        $this->openAiChat();
        $this->send('are you there?');

        $this->assertSame(OrderState::MainMenu->value, $this->state());
    }

    public function test_an_empty_answer_is_treated_as_a_failure(): void
    {
        $this->withAi();
        $this->fakeDeepSeekSays('   ');

        $this->openAiChat();
        $this->send('hello?');

        $this->assertSame(OrderState::MainMenu->value, $this->state());
    }

    // ---- the escape hatch ------------------------------------------------

    /**
     * The one thing that must never break. A customer who cannot leave the
     * assistant cannot order, and the shop loses the sale to its own bot.
     */
    public function test_a_reset_word_always_escapes_the_assistant(): void
    {
        $this->withAi();
        $this->fakeDeepSeekSays('Some answer.');

        $this->openAiChat();
        $this->send('a question');
        $this->assertSame(OrderState::AiChat->value, $this->state());

        $this->send('menu');

        $this->assertSame(OrderState::MainMenu->value, $this->state());
    }

    /** Escaping must not cost the reseller an answer they did not need. */
    public function test_escaping_does_not_call_deepseek(): void
    {
        $this->withAi();
        $this->fakeDeepSeekSays('Some answer.');

        $this->openAiChat();
        $this->send('menu');

        Http::assertNothingSent();
    }

    // ---- what the reseller is billed for ---------------------------------

    public function test_an_answer_is_counted(): void
    {
        $ai = $this->withAi();
        $this->fakeDeepSeekSays('Yes.');

        $this->openAiChat();
        $this->send('a question');
        $this->send('another question');

        $this->assertSame(2, (int) $ai->fresh()->answers_today);
        $this->assertSame(2, (int) $ai->fresh()->answers_total);
    }

    /** A failed call costs the reseller nothing, so it must not be counted. */
    public function test_a_failed_answer_is_not_counted(): void
    {
        $ai = $this->withAi();
        Http::fake(['api.deepseek.com/*' => Http::response([], 500)]);

        $this->openAiChat();
        $this->send('a question');

        $this->assertSame(0, (int) $ai->fresh()->answers_total);
    }

    /**
     * Yesterday's count belongs to yesterday. A reseller opening the dashboard
     * in the morning must not read it as today's.
     */
    public function test_yesterdays_daily_count_reads_as_zero_today(): void
    {
        $ai = $this->withAi();
        $ai->update(['answers_today' => 40, 'counting_day' => now()->subDay()]);

        $this->assertSame(0, $ai->fresh()->answersToday());
    }

    public function test_the_daily_count_rolls_over_rather_than_accumulating(): void
    {
        $ai = $this->withAi();
        $ai->update([
            'answers_today' => 40,
            'answers_total' => 40,
            'counting_day' => now()->subDay(),
        ]);

        $this->fakeDeepSeekSays('Yes.');
        $this->openAiChat();
        $this->send('a question');

        $fresh = $ai->fresh();

        $this->assertSame(1, (int) $fresh->answers_today, 'today restarts');
        $this->assertSame(41, (int) $fresh->answers_total, 'the lifetime total does not');
    }

    // ---- what the assistant is told --------------------------------------

    /**
     * The prompt carries the reseller's real prices. Without them the
     * assistant invents one, and a customer quoted an invented price arrives
     * expecting it.
     */
    public function test_the_prompt_carries_the_shops_own_services_and_prices(): void
    {
        BotService::factory()->for($this->tenant)->create([
            'platform' => 'Instagram',
            'name' => 'IG Followers',
            'my_price' => '2.5000',
            'status' => BotService::ACTIVE,
        ]);

        $prompt = ShopContext::for($this->tenant, ['currency' => 'TZS']);

        $this->assertStringContainsString('IG Followers', $prompt);
        $this->assertStringContainsString('2.5', $prompt);
        $this->assertStringContainsString('TZS', $prompt);
        $this->assertStringContainsString('Kuza Panel', $prompt);
    }

    /**
     * A hidden or paused service cannot be ordered, so quoting it invites a
     * customer to ask for something the bot will then refuse.
     */
    public function test_the_prompt_leaves_out_services_that_cannot_be_ordered(): void
    {
        BotService::factory()->for($this->tenant)->create([
            'name' => 'Hidden Service',
            'status' => BotService::HIDDEN,
        ]);

        BotService::factory()->for($this->tenant)->create([
            'name' => 'Paused Service',
            'status' => BotService::PAUSED,
        ]);

        $prompt = ShopContext::for($this->tenant, []);

        $this->assertStringNotContainsString('Hidden Service', $prompt);
        $this->assertStringNotContainsString('Paused Service', $prompt);
    }

    /** Another reseller's catalogue must never leak into this shop's prompt. */
    public function test_the_prompt_carries_no_other_tenants_services(): void
    {
        $other = Tenant::factory()->create();

        BotService::factory()->for($other)->create([
            'name' => 'Someone Elses Service',
            'status' => BotService::ACTIVE,
        ]);

        $prompt = ShopContext::for($this->tenant, []);

        $this->assertStringNotContainsString('Someone Elses Service', $prompt);
    }

    /** The rules are what stop the assistant guessing, so they must be present. */
    public function test_the_prompt_forbids_inventing_prices_and_taking_orders(): void
    {
        $prompt = ShopContext::for($this->tenant, []);

        $this->assertStringContainsString('Never invent', $prompt);
        $this->assertStringContainsString('cannot place orders', $prompt);
    }

    /** A shop with nothing listed must not leave the assistant free to improvise. */
    public function test_an_empty_catalogue_is_stated_rather_than_left_blank(): void
    {
        $prompt = ShopContext::for($this->tenant, []);

        $this->assertStringContainsString('no services listed', $prompt);
    }

    // ---- availability ----------------------------------------------------

    public function test_availability_needs_both_the_subscription_and_a_key(): void
    {
        $answers = app(AiAnswers::class);
        $tenantId = (int) $this->tenant->id;

        $this->assertFalse($answers->isAvailable($tenantId), 'nothing configured');

        Subscription::factory()->for($this->tenant)->active()->create([
            'service_key' => ServiceKey::AiChat,
        ]);
        $this->assertFalse($answers->isAvailable($tenantId), 'subscribed but no key');

        $ai = TenantAi::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'deepseek_api_key_enc' => 'sk-deepseek',
            'status' => 'active',
        ]);
        $this->assertTrue($answers->isAvailable($tenantId), 'both held');

        $ai->update(['status' => 'inactive']);
        $this->assertFalse($answers->isAvailable($tenantId), 'paused');
    }
}
