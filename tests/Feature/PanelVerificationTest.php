<?php

namespace Tests\Feature;

use App\Models\BotConversation;
use App\Models\PanelAccountLink;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Bots\BotSettings;
use App\Services\Bots\Support\SupportBotHandler;
use App\Services\Bots\Support\SupportState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeBotMessenger;
use Tests\TestCase;

/**
 * A customer proves an account on the reseller's panel is theirs with a code
 * put in that account's own tickets, and from then on the bot acts only on that
 * account's orders, in the panel's own words.
 */
class PanelVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '255700000001';

    private const STAFF = '255700000099';

    private Tenant $tenant;

    private FakeBotMessenger $messenger;

    private TenantPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        RateLimiter::clear('panel-link:1:'.self::CUSTOMER);

        $this->tenant = Tenant::factory()->create(['business_name' => 'Kuza Panel']);
        $this->messenger = new FakeBotMessenger;
        $this->panel = TenantPanel::factory()->for($this->tenant)->create([
            'name' => 'My Panel',
            'admin_api_url' => 'https://panel.example.com',
            'admin_api_key_enc' => 'admin-secret',
        ]);

        BotSettings::save($this->tenant->id, 'support', ['staff' => ['numbers' => [self::STAFF]]]);
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

    private function said(): string
    {
        return json_encode(array_values(array_filter(
            $this->messenger->sent,
            fn (array $message) => $message['to'] === self::CUSTOMER,
        )), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function lastSaid(): string
    {
        $messages = array_values(array_filter(
            $this->messenger->sent,
            fn (array $message) => $message['to'] === self::CUSTOMER,
        ));

        return $messages === [] ? '' : (string) end($messages)['body'];
    }

    /** The six digits the panel was asked to put in the customer's ticket. */
    private function codeSent(): ?string
    {
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/tickets')
                && preg_match('/\b(\d{6})\b/', (string) ($request['message'] ?? ''), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function sentTo(string $needle, string $method = 'POST'): bool
    {
        return Http::recorded(fn (Request $request) => $request->method() === $method && str_contains($request->url(), $needle))->isNotEmpty();
    }

    /** An order on the panel, owned by user 7. */
    private function order(array $over = []): array
    {
        return $over + [
            'id' => 555,
            'status' => 'completed',
            'user' => ['id' => 7, 'username' => 'asha', 'email' => 'asha@example.test'],
            'service' => 'Instagram Followers',
            'start_count' => 100,
            'remains' => 0,
            'refill' => true,
            'refill_days' => 30,
            'refill_blocker' => null,
        ];
    }

    private function fakePanel(array $extra = []): void
    {
        Http::fake($extra + [
            'panel.example.com/api/admin/users/7/tickets' => Http::response(['ticket' => ['id' => 1]], 201),
            'panel.example.com/api/admin/users*' => Http::response(['data' => [['id' => 7, 'username' => 'asha']]]),
            'panel.example.com/api/admin/orders/555/refill' => Http::response(['refill' => '9']),
            'panel.example.com/api/admin/orders/555' => Http::response($this->order()),
        ]);
    }

    /** Walk the customer through verification as user 7. */
    private function verified(array $extra = []): void
    {
        $this->fakePanel($extra);

        $this->send('hi');
        $this->send('1');
        $this->send('asha');
        $this->send($this->codeSent());
    }

    // ---- being asked to verify ----------------------------------------------

    public function test_an_unverified_customer_is_asked_to_verify_before_an_order_id(): void
    {
        $this->fakePanel();

        $this->send('hi');
        $this->send('1');

        $this->assertSame(SupportState::AwaitAccount->value, $this->state());
        $this->assertStringContainsString('Verify your account', $this->lastSaid());
        $this->assertStringNotContainsString('Order ID', $this->lastSaid());
    }

    public function test_the_word_verify_starts_it_from_anywhere(): void
    {
        $this->fakePanel();

        $this->send('verify');

        $this->assertSame(SupportState::AwaitAccount->value, $this->state());
    }

    public function test_without_the_admin_api_nothing_changes(): void
    {
        $this->panel->forceFill(['admin_api_url' => null, 'admin_api_key_enc' => null])->save();
        Http::fake();

        $this->send('hi');
        $this->send('1');

        $this->assertSame(SupportState::AwaitOrderId->value, $this->state());
        $this->send('verify');
        $this->assertStringNotContainsString('Verify your account', $this->lastSaid());
    }

    public function test_the_reseller_can_switch_verification_off(): void
    {
        BotSettings::save($this->tenant->id, 'support', ['verify' => ['required' => false]]);
        Http::fake();

        $this->send('hi');
        $this->send('1');

        $this->assertSame(SupportState::AwaitOrderId->value, $this->state());
    }

    // ---- proving it --------------------------------------------------------

    public function test_the_whole_flow_ends_in_a_refill_on_the_customers_own_order(): void
    {
        $this->verified();

        $this->assertStringContainsString('Verified as *asha*', $this->said());
        $this->assertSame(SupportState::AwaitOrderId->value, $this->state());

        $this->send('555');

        $this->assertStringContainsString('Refill for *#555* submitted', $this->said());
        $this->assertStringContainsString('30 days', $this->said());
        $this->assertTrue($this->sentTo('/orders/555/refill'));

        $link = PanelAccountLink::withoutTenantScope()->first();
        $this->assertSame(7, $link->panel_user_id);
        $this->assertNotNull($link->verified_at);
        $this->assertNull($link->code_hash);
    }

    public function test_the_admin_key_is_sent_as_a_bearer_token(): void
    {
        $this->verified();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer admin-secret'));
    }

    public function test_the_code_is_put_in_the_customers_ticket_and_stored_only_as_a_hash(): void
    {
        $this->fakePanel();
        $this->send('hi');
        $this->send('1');
        $this->send('asha');

        $code = $this->codeSent();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $link = PanelAccountLink::withoutTenantScope()->first();
        $this->assertNotSame($code, $link->code_hash);
        $this->assertStringNotContainsString($code, $this->said());
    }

    public function test_a_wrong_code_counts_down_and_then_asks_to_start_again(): void
    {
        $this->fakePanel();
        $this->send('hi');
        $this->send('1');
        $this->send('asha');
        $real = $this->codeSent();
        $wrong = $real === '111111' ? '222222' : '111111';

        $this->send($wrong);
        $this->assertStringContainsString('4 tries left', $this->lastSaid());

        foreach (range(1, 4) as $_) {
            $this->send($wrong);
        }

        $this->assertStringContainsString('expired', $this->lastSaid());
        $this->assertSame(SupportState::AwaitAccount->value, $this->state());

        // The right code no longer works once the tries are spent.
        $this->send($real);
        $this->assertNull(PanelAccountLink::withoutTenantScope()->whereNotNull('verified_at')->first());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->fakePanel();
        $this->send('hi');
        $this->send('1');
        $this->send('asha');
        $code = $this->codeSent();

        PanelAccountLink::withoutTenantScope()->update(['code_expires_at' => now()->subMinute()]);

        $this->send($code);

        $this->assertStringContainsString('expired', $this->lastSaid());
        $this->assertNull(PanelAccountLink::withoutTenantScope()->whereNotNull('verified_at')->first());
    }

    public function test_an_unknown_account_gets_the_same_reply_and_no_ticket(): void
    {
        Http::fake(['panel.example.com/api/admin/users*' => Http::response(['data' => []])]);

        $this->send('hi');
        $this->send('1');
        $this->send('nobody');

        $this->assertStringContainsString('If that account exists', $this->lastSaid());
        $this->assertFalse($this->sentTo('/tickets'));
        $this->assertSame(0, PanelAccountLink::withoutTenantScope()->count());
    }

    public function test_code_requests_are_limited(): void
    {
        $this->fakePanel();

        foreach (range(1, 3) as $_) {
            $this->send('verify');
            $this->send('asha');
        }

        $this->send('verify');
        $this->send('asha');

        $this->assertStringContainsString('try again in an hour', $this->lastSaid());
    }

    public function test_a_panel_that_cannot_be_reached_is_not_reported_as_an_unknown_account(): void
    {
        Http::fake(['panel.example.com/*' => Http::response(['error' => 'Invalid API key.'], 401)]);

        $this->send('hi');
        $this->send('1');
        $this->send('asha');

        $this->assertStringContainsString("couldn't reach your account", $this->lastSaid());
    }

    // ---- acting only on the customers own orders ----------------------------

    public function test_someone_elses_order_is_refused_and_nothing_is_asked_of_the_panel(): void
    {
        $this->verified();
        Http::fake([
            'panel.example.com/api/admin/orders/777' => Http::response($this->order(['id' => 777, 'user' => ['id' => 99, 'username' => 'other']])),
        ]);

        $this->send('777');

        $this->assertStringContainsString("isn't on your account", $this->said());
        $this->assertFalse($this->sentTo('/orders/777/refill'));
    }

    public function test_an_order_the_panel_does_not_know_reads_the_same_as_someone_elses(): void
    {
        $this->verified();
        Http::fake(['panel.example.com/api/admin/orders/888' => Http::response(['error' => 'No query results'], 404)]);

        $this->send('888');

        $this->assertStringContainsString("isn't on your account", $this->said());
    }

    public function test_the_panels_own_refusal_is_passed_on(): void
    {
        // Stubs are matched in the order they were registered, so this one goes first.
        $this->verified(['panel.example.com/api/admin/orders/555/refill' => Http::response(['error' => 'Refill not available for this service'], 422)]);

        $this->send('555');

        $this->assertStringContainsString("couldn't be submitted: Refill not available for this service", $this->said());
    }

    public function test_status_comes_from_the_panel(): void
    {
        $this->verified();
        $this->messenger->sent = [];

        $this->send('0');
        $this->send('6');
        $this->send('555');

        $this->assertStringContainsString('Status: *Completed*', $this->said());
        $this->assertStringContainsString('Instagram Followers', $this->said());
    }

    public function test_a_cancel_the_panel_allows_is_done_and_reported(): void
    {
        $this->verified();
        Http::fake(['panel.example.com/api/admin/orders/555/cancel' => Http::response(['order' => $this->order(['status' => 'canceled'])])]);
        $this->messenger->sent = [];

        $this->send('0');
        $this->send('3');
        $this->send('555');

        $this->assertStringContainsString('has been cancelled', $this->said());
        $this->assertTrue($this->sentTo('/orders/555/cancel'));
    }

    public function test_a_cancel_the_key_may_not_do_becomes_a_request_for_the_team(): void
    {
        $this->verified();
        Http::fake(['panel.example.com/api/admin/orders/555/cancel' => Http::response(['error' => 'This key is not permitted to cancel or refund orders.'], 403)]);
        $this->messenger->sent = [];

        $this->send('0');
        $this->send('3');
        $this->send('555');

        $this->assertStringContainsString('Our team will confirm', $this->said());
        $this->assertStringContainsString('Cancel requested', json_encode($this->messenger->sent));
    }

    public function test_a_cancel_the_panel_refuses_shows_the_panels_reason(): void
    {
        $this->verified();
        Http::fake(['panel.example.com/api/admin/orders/555/cancel' => Http::response(['error' => 'Only pending orders can be cancelled.'], 422)]);
        $this->messenger->sent = [];

        $this->send('0');
        $this->send('3');
        $this->send('555');

        $this->assertStringContainsString("couldn't be cancelled: Only pending orders can be cancelled.", $this->said());
    }

    // ---- several orders in one message ----------------------------------------

    /** Orders 555 and 556 are the customer's (user 7); 777 is somebody else's; 888 is unknown. */
    private function manyOrders(array $extra = []): array
    {
        return $extra + [
            'panel.example.com/api/admin/orders/556/refill' => Http::response(['refill' => '10']),
            'panel.example.com/api/admin/orders/556' => Http::response($this->order(['id' => 556, 'status' => 'processing', 'remains' => 40])),
            'panel.example.com/api/admin/orders/777' => Http::response($this->order(['id' => 777, 'user' => ['id' => 99, 'username' => 'other']])),
            'panel.example.com/api/admin/orders/888' => Http::response(['error' => 'No query results'], 404),
        ];
    }

    private function ready(string $menuChoice, array $extra = []): void
    {
        $this->verified($this->manyOrders($extra));
        $this->messenger->sent = [];
        $this->send('0');
        $this->send($menuChoice);
    }

    private function panelCalls(string $needle, string $method = 'POST'): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === $method && str_contains($request->url(), $needle))->count();
    }

    public function test_several_ids_pasted_on_separate_lines_are_read_as_separate_orders(): void
    {
        $this->ready('6');
        $this->send("El número de órdenes son\n\n555\n\n556\n777");

        $said = $this->said();
        $this->assertStringContainsString('#555 — Completed', $said);
        $this->assertStringContainsString('#556 — Processing (remaining 40)', $said);
        $this->assertStringContainsString("#777 — isn't on your account", $said);
    }

    public function test_the_answer_is_one_message_not_one_per_order(): void
    {
        $this->ready('6');
        $before = count($this->messenger->sent);

        $this->send('555 556 777');

        // The summary, and the usual "Reply 0" line: two messages, not four.
        $this->assertSame(2, count($this->messenger->sent) - $before);
    }

    public function test_ids_are_not_squeezed_into_one_number(): void
    {
        $this->ready('6');
        $this->send("555\n556");

        $this->assertTrue(Http::recorded(fn (Request $request) => str_contains($request->url(), '555556'))->isEmpty());
        $this->assertStringNotContainsString('Incorrect order', $this->said());
    }

    public function test_repeated_ids_are_asked_about_once(): void
    {
        $this->ready('6');
        $this->send('555 556 555 556');

        $this->assertSame(1, substr_count($this->said(), '#555 — '));
    }

    public function test_more_than_ten_orders_are_refused_without_asking_the_panel(): void
    {
        $this->ready('6');
        $before = Http::recorded()->count();

        $this->send(implode(' ', range(100001, 100011)));

        $this->assertStringContainsString('up to 10 order IDs', $this->said());
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_several_refills_each_get_the_panels_own_answer(): void
    {
        $this->ready('1', ['panel.example.com/api/admin/orders/556/refill' => Http::response(['error' => 'Refill already requested'], 422)]);
        $this->send('555 556 777');

        $said = $this->said();
        $this->assertStringContainsString('#555 — refill submitted', $said);
        $this->assertStringContainsString('#556 — Refill already requested', $said);
        $this->assertStringContainsString("#777 — isn't on your account", $said);
        $this->assertFalse($this->sentTo('/orders/777/refill'));
    }

    public function test_cancelling_several_orders_asks_first_and_does_nothing_until_yes(): void
    {
        $this->ready('3');
        $this->send('555 556');

        $this->assertSame(SupportState::AwaitConfirm->value, $this->state());
        $this->assertStringContainsString('Cancel *2* orders?', $this->said());
        $this->assertSame(0, $this->panelCalls('/cancel'));
    }

    public function test_yes_cancels_each_of_them_and_the_team_hears_once(): void
    {
        $this->ready('3', [
            'panel.example.com/api/admin/orders/555/cancel' => Http::response(['order' => $this->order(['status' => 'canceled'])]),
            'panel.example.com/api/admin/orders/556/cancel' => Http::response(['error' => 'This order can no longer be cancelled.'], 422),
        ]);
        $this->send('555 556 777');
        $this->send('yes');

        $said = $this->said();
        $this->assertStringContainsString('#555 — cancelled', $said);
        $this->assertStringContainsString('#556 — This order can no longer be cancelled.', $said);
        $this->assertStringContainsString("#777 — isn't on your account", $said);
        $this->assertSame(2, $this->panelCalls('/cancel'));

        $alerts = array_values(array_filter($this->messenger->sent, fn ($m) => $m['to'] === self::STAFF));
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('cancelled #555', json_encode($alerts));
    }

    public function test_anything_but_yes_cancels_nothing(): void
    {
        $this->ready('3');
        $this->send('555 556');
        $this->send('no thanks');

        $this->assertStringContainsString('Nothing was cancelled', $this->said());
        $this->assertSame(0, $this->panelCalls('/cancel'));
        $this->assertSame(SupportState::Menu->value, $this->state());
    }

    public function test_a_key_that_may_not_cancel_turns_each_into_a_request_for_the_team(): void
    {
        $this->ready('3', [
            'panel.example.com/api/admin/orders/555/cancel' => Http::response(['error' => 'not permitted'], 403),
            'panel.example.com/api/admin/orders/556/cancel' => Http::response(['error' => 'not permitted'], 403),
        ]);
        $this->send('555 556');
        $this->send('yes');

        $this->assertSame(2, substr_count($this->said(), 'requested, our team will confirm'));
        $this->assertStringContainsString('needs you', json_encode($this->messenger->sent));
    }

    public function test_without_the_admin_api_each_order_is_still_dealt_with_separately(): void
    {
        $this->panel->forceFill(['admin_api_url' => null, 'admin_api_key_enc' => null])->save();
        Http::fake(['panel.example.com/api/v2' => Http::response(['status' => 'Completed', 'start_count' => '1', 'remains' => '0'])]);

        $this->send('hi');
        $this->send('6');
        $this->send("555\n556");

        $orders = Http::recorded(fn (Request $request) => str_contains($request->url(), '/api/v2'))
            ->map(fn ($pair) => $pair[0]['order'])->values()->all();

        $this->assertSame(['555', '556'], $orders);
    }

    public function test_a_single_id_with_letters_is_still_one_order(): void
    {
        $this->panel->forceFill(['admin_api_url' => null, 'admin_api_key_enc' => null])->save();
        Http::fake(['panel.example.com/api/v2' => Http::response(['status' => 'Completed'])]);

        $this->send('hi');
        $this->send('6');
        $this->send('AB-48220');

        $orders = Http::recorded(fn (Request $request) => str_contains($request->url(), '/api/v2'))
            ->map(fn ($pair) => $pair[0]['order'])->values()->all();

        $this->assertSame(['AB-48220'], $orders);
    }
    // ---- staying linked, and leaving ----------------------------------------

    public function test_a_linked_customer_goes_straight_to_the_order_id_next_time(): void
    {
        $this->verified();
        $this->send('0');
        $this->send('1');

        $this->assertSame(SupportState::AwaitOrderId->value, $this->state());
    }

    public function test_unlink_disconnects_the_account(): void
    {
        $this->verified();

        $this->send('unlink');

        $this->assertStringContainsString('disconnected', $this->lastSaid());
        $this->assertSame(0, PanelAccountLink::withoutTenantScope()->count());

        $this->send('hi');
        $this->send('1');
        $this->assertSame(SupportState::AwaitAccount->value, $this->state());
    }

    public function test_a_link_belongs_to_one_tenant_only(): void
    {
        $this->verified();

        $other = Tenant::factory()->create();
        $otherPanel = TenantPanel::factory()->for($other)->create([
            'admin_api_url' => 'https://other.example.com',
            'admin_api_key_enc' => 'other-key',
        ]);
        Http::fake(['other.example.com/*' => Http::response(['data' => []])]);

        (new SupportBotHandler($other, $this->messenger))->handle(self::CUSTOMER, 'hi');
        (new SupportBotHandler($other, $this->messenger))->handle(self::CUSTOMER, '1');

        // The same phone is a stranger on another reseller's panel.
        $this->assertSame(SupportState::AwaitAccount->value, BotConversation::withoutTenantScope()->where('tenant_id', $other->id)->value('state'));
        $this->assertNull(app(\App\Services\Panel\AccountLinker::class)->linked($otherPanel, self::CUSTOMER));
    }

    // ---- the reseller's side -------------------------------------------------

    public function test_the_admin_api_is_saved_encrypted_and_never_sent_back(): void
    {
        Http::fake(['newpanel.example.com/api/admin/stats' => Http::response(['ok' => true])]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.admin-api'), [
                'adminApiUrl' => 'https://newpanel.example.com',
                'adminApiKey' => 'brand-new-key',
                'required' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->panel->refresh();
        $this->assertSame('https://newpanel.example.com', $this->panel->admin_api_url);
        $this->assertSame('brand-new-key', $this->panel->admin_api_key_enc);
        $this->assertNotSame('brand-new-key', $this->panel->getRawOriginal('admin_api_key_enc'));

        $this->actingAs($this->tenant)
            ->get(route('support-bot', 'settings'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.verification.hasKey', true)
                ->where('settings.verification.adminApiUrl', 'https://newpanel.example.com')
                ->missing('settings.verification.adminApiKey'));
    }

    public function test_a_blank_key_keeps_the_one_already_saved(): void
    {
        Http::fake(['panel.example.com/api/admin/stats' => Http::response(['ok' => true])]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.admin-api'), ['adminApiUrl' => 'https://panel.example.com', 'adminApiKey' => '', 'required' => false])
            ->assertSessionHas('success');

        $this->assertSame('admin-secret', $this->panel->fresh()->admin_api_key_enc);
        $this->assertFalse((bool) BotSettings::for($this->tenant->id, 'support')['verify']['required']);
    }

    public function test_a_key_the_panel_rejects_is_saved_but_reported(): void
    {
        Http::fake(['panel.example.com/api/admin/stats' => Http::response(['error' => 'Invalid API key.'], 401)]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.admin-api'), ['adminApiUrl' => 'https://panel.example.com', 'adminApiKey' => 'wrong', 'required' => true])
            ->assertSessionHas('error');

        $this->assertSame('wrong', $this->panel->fresh()->admin_api_key_enc);
    }

    public function test_the_admin_api_can_be_disconnected(): void
    {
        $this->actingAs($this->tenant)
            ->post(route('support-bot.admin-api'), ['required' => true, 'clear' => true])
            ->assertSessionHas('success');

        $this->assertNull($this->panel->fresh()->admin_api_url);
        $this->assertNull($this->panel->fresh()->getRawOriginal('admin_api_key_enc'));
    }

    public function test_another_resellers_panel_is_never_touched(): void
    {
        $other = Tenant::factory()->create();
        $theirs = TenantPanel::factory()->for($other)->create(['admin_api_url' => 'https://theirs.example.com', 'admin_api_key_enc' => 'theirs']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->actingAs($this->tenant)
            ->post(route('support-bot.admin-api'), ['adminApiUrl' => 'https://mine.example.com', 'adminApiKey' => 'mine', 'required' => true]);

        $this->assertSame('theirs', $theirs->fresh()->admin_api_key_enc);
    }

    public function test_the_address_may_be_given_with_or_without_the_api_path(): void
    {
        foreach (['https://p.example.com', 'https://p.example.com/', 'https://p.example.com/api/admin', 'https://p.example.com/api/v2'] as $given) {
            $this->assertSame('https://p.example.com/api/admin', \App\Services\Panel\PanelAdminClient::normalise($given));
        }
    }
}
