<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantTelegramBot;
use App\Services\Bots\Telegram\TelegramFormat;
use App\Services\Bots\Telegram\TelegramMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Telegram as a second channel: connecting a bot, the webhook's checks, and a
 * conversation that reaches the same handler WhatsApp does.
 */
class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:ABCDEFghijklmnopqrstuvwxyz0123456789';

    private const SECRET = 'header-secret';

    private function bot(bool $subscribed = true): TenantTelegramBot
    {
        $tenant = Tenant::factory()->create();

        if ($subscribed) {
            Subscription::factory()->for($tenant)->active()->create(['service_key' => ServiceKey::OrderBot]);
        }

        return TenantTelegramBot::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'bot_type' => 'order',
            'token_enc' => self::TOKEN,
            'telegram_bot_id' => 123456,
            'bot_username' => 'shop_bot',
            'public_id' => 'pub123',
            'webhook_secret' => self::SECRET,
            'status' => 'active',
        ]);
    }

    private function update(array $overrides = []): array
    {
        return array_replace_recursive([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Asha', 'username' => 'asha'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'text' => '/start',
            ],
        ], $overrides);
    }

    private function hook(array $update, ?string $secret = self::SECRET, string $id = 'pub123')
    {
        return $this->postJson("/webhooks/telegram/{$id}", $update, $secret === null ? [] : [
            'X-Telegram-Bot-Api-Secret-Token' => $secret,
        ]);
    }

    private function sent(string $method): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), "/{$method}"))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    // ---- the front door ----------------------------------------------------

    public function test_a_start_message_gets_the_menu_over_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $bot = $this->bot();

        $this->hook($this->update())->assertOk()->assertSee('handled_order');

        $messages = $this->sent('sendMessage');
        $this->assertNotEmpty($messages);
        $this->assertSame('555', (string) $messages[0]['chat_id']);
        $this->assertSame('HTML', $messages[0]['parse_mode']);

        // The customer is known by a chat key, and both sides are logged.
        $this->assertDatabaseHas('bot_messages', ['tenant_id' => $bot->tenant_id, 'customer_phone' => 'tg555', 'direction' => 'in']);
        $this->assertDatabaseHas('bot_messages', ['tenant_id' => $bot->tenant_id, 'customer_phone' => 'tg555', 'direction' => 'out']);
        $this->assertDatabaseHas('bot_customers', ['tenant_id' => $bot->tenant_id, 'phone' => 'tg555', 'name' => 'Asha']);
    }

    public function test_the_menu_is_an_inline_keyboard(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->bot();

        $this->hook($this->update());

        $keyboard = collect($this->sent('sendMessage'))->pluck('reply_markup.inline_keyboard')->filter()->first();
        $this->assertNotEmpty($keyboard);
        $this->assertArrayHasKey('callback_data', $keyboard[0][0]);
    }

    public function test_a_wrong_or_missing_secret_is_refused(): void
    {
        Http::fake();
        $this->bot();

        $this->hook($this->update(), 'wrong')->assertForbidden();
        $this->hook($this->update(), null)->assertForbidden();
        $this->hook($this->update(), self::SECRET, 'nope')->assertForbidden();

        $this->assertDatabaseCount('bot_messages', 0);
        Http::assertNothingSent();
    }

    public function test_a_redelivered_update_is_answered_once(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->bot();

        $this->hook($this->update())->assertSee('handled_order');
        $this->hook($this->update())->assertSee('duplicate');

        $this->assertDatabaseCount('bot_messages', 1 + \App\Models\BotMessage::where('direction', 'out')->count());
        $this->assertSame(1, \App\Models\BotMessage::where('direction', 'in')->count());
    }

    public function test_groups_and_bots_are_ignored(): void
    {
        Http::fake();
        $this->bot();

        $this->hook($this->update(['message' => ['chat' => ['type' => 'group']]]))->assertSee('no_message');
        $this->hook($this->update(['update_id' => 2, 'message' => ['from' => ['is_bot' => true]]]))->assertSee('no_message');

        $this->assertDatabaseCount('bot_messages', 0);
    }

    public function test_a_button_tap_is_answered_and_routed_by_its_id(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->bot();

        $this->hook([
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq1',
                'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Asha'],
                'data' => 'new_order',
                'message' => ['message_id' => 11, 'chat' => ['id' => 555, 'type' => 'private']],
            ],
        ])->assertOk();

        $this->assertSame('cbq1', $this->sent('answerCallbackQuery')[0]['callback_query_id']);
        $this->assertDatabaseHas('bot_messages', ['customer_phone' => 'tg555', 'direction' => 'in', 'message' => 'new_order']);
    }

    public function test_without_a_subscription_the_customer_is_told_it_is_paused(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->bot(subscribed: false);

        $this->hook($this->update())->assertSee('gate_locked');

        $this->assertStringContainsString('paused', $this->sent('sendMessage')[0]['text']);
    }

    // ---- sending -----------------------------------------------------------

    public function test_a_long_button_id_survives_the_64_byte_limit(): void
    {
        $id = 'cat_'.rawurlencode(str_repeat('Instagram Followers ', 6));
        $data = TelegramMessenger::callbackData($id);

        $this->assertLessThanOrEqual(64, strlen($data));
        $this->assertSame($id, TelegramMessenger::resolveCallback($data));
        $this->assertSame('cur:USD', TelegramMessenger::callbackData('cur:USD'));
    }

    public function test_whatsapp_markup_becomes_safe_html(): void
    {
        $this->assertSame(
            '<b>Total</b> 5 &lt;USD&gt; &amp; <i>fast</i> snake_case_word 2*3',
            TelegramFormat::toHtml('*Total* 5 <USD> & _fast_ snake_case_word 2*3'),
        );
        $this->assertSame('Total fast', TelegramFormat::toPlain('*Total* _fast_'));
    }

    public function test_text_telegram_cannot_parse_is_resent_plain(): void
    {
        Http::fakeSequence('api.telegram.org/*')
            ->push(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"], 400)
            ->push(['ok' => true]);
        $bot = $this->bot();

        $messenger = app(\App\Services\Bots\BotMessengerFactory::class)->forTelegram($bot, $bot->tenant);

        $this->assertTrue($messenger->sendText('tg555', '*Hi*'));
        $this->assertSame('Hi', $this->sent('sendMessage')[1]['text']);
    }

    public function test_a_blocked_bot_is_reported_as_not_sent(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        $bot = $this->bot();

        $messenger = app(\App\Services\Bots\BotMessengerFactory::class)->forTelegram($bot, $bot->tenant);

        $this->assertFalse($messenger->sendText('tg555', 'hello'));
        $this->assertCount(1, $this->sent('sendMessage'));
    }

    // ---- connecting --------------------------------------------------------

    private function signedInTenant(): Tenant
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs($tenant);

        return $tenant;
    }

    public function test_connecting_checks_the_token_and_sets_the_webhook(): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777, 'username' => 'my_shop_bot']]),
            'api.telegram.org/*/setWebhook' => Http::response(['ok' => true]),
        ]);
        $tenant = $this->signedInTenant();

        $this->post('/telegram-bot', ['token' => self::TOKEN, 'bot_type' => 'order'])
            ->assertSessionHasNoErrors();

        $row = TenantTelegramBot::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('my_shop_bot', $row->bot_username);
        $this->assertSame(self::TOKEN, $row->token_enc);
        $this->assertNotSame(self::TOKEN, $row->getRawOriginal('token_enc'));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === $row->webhook_secret
            && $request['url'] === route('webhooks.telegram', $row->public_id));
    }

    public function test_a_token_telegram_rejects_connects_nothing(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401], 401)]);
        $this->signedInTenant();

        $this->post('/telegram-bot', ['token' => self::TOKEN, 'bot_type' => 'order'])
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('tenant_telegram_bots', 0);
    }

    public function test_a_bot_already_used_by_another_account_is_refused(): void
    {
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'username' => 'shop_bot']])]);
        $this->bot();
        $this->signedInTenant();

        $this->post('/telegram-bot', ['token' => self::TOKEN, 'bot_type' => 'order'])
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('tenant_telegram_bots', 1);
    }

    public function test_disconnecting_removes_the_webhook_and_the_row(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $bot = $this->bot();
        $this->actingAs($bot->tenant);

        $this->delete("/telegram-bot/{$bot->id}")->assertRedirect();

        $this->assertDatabaseCount('tenant_telegram_bots', 0);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/deleteWebhook'));
    }

    public function test_another_tenants_bot_cannot_be_disconnected(): void
    {
        Http::fake();
        $bot = $this->bot();
        $this->signedInTenant();

        $this->delete("/telegram-bot/{$bot->id}")->assertNotFound();

        $this->assertDatabaseCount('tenant_telegram_bots', 1);
    }
}
