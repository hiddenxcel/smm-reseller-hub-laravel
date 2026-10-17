<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantTelegramBot;
use App\Models\TenantWhatsApp;
use App\Services\Broadcasts\Audience;
use App\Services\Broadcasts\BroadcastSender;
use App\Services\Broadcasts\Broadcasts;
use App\Services\Bots\BotRouter;
use App\Services\Bots\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantWhatsApp $number;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasts.whatsapp_pause_ms' => 0]);

        $this->tenant = Tenant::factory()->create();
        Subscription::factory()->for($this->tenant)->active()->create(['service_key' => ServiceKey::OrderBot]);
        $this->number = TenantWhatsApp::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function customer(string $phone, array $attributes = []): BotCustomer
    {
        return BotCustomer::withoutTenantScope()->create($attributes + [
            'tenant_id' => $this->tenant->id,
            'phone' => $phone,
            'name' => 'Customer '.$phone,
            'lang' => 'en',
        ]);
    }

    private function wroteJustNow(string $phone, int $hoursAgo = 1): void
    {
        $message = BotMessage::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'customer_phone' => $phone,
            'direction' => 'in',
            'message' => 'hi',
            'bot_type' => 'order',
        ]);

        BotMessage::withoutTenantScope()->whereKey($message->id)->update(['created_at' => now()->subHours($hoursAgo)]);
    }

    private function telegramBot(): void
    {
        TenantTelegramBot::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'bot_type' => 'order',
            'token_enc' => '123:abc',
            'telegram_bot_id' => 999,
            'bot_username' => 'shop_bot',
            'public_id' => 'pub1',
            'webhook_secret' => 'secret',
            'status' => 'active',
        ]);
    }

    private function fakeChannels(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function audienceFixture(): void
    {
        $this->customer('255700000001');
        $this->wroteJustNow('255700000001');          // inside the 24 hours
        $this->customer('255700000002');
        $this->wroteJustNow('255700000002', 30);      // wrote 30 hours ago
        $this->customer('tg555');                     // Telegram
        $this->customer('255700000004', ['blocked_at' => now()]);
        $this->wroteJustNow('255700000004');
        $this->customer('255700000005', ['broadcast_opt_out_at' => now()]);
        $this->wroteJustNow('255700000005');
    }

    public function test_the_summary_separates_who_can_be_reached_now(): void
    {
        $this->telegramBot();
        $this->audienceFixture();

        $summary = Audience::for($this->tenant->id)->summary('all');

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['now']);
        $this->assertSame(1, $summary['whatsapp']);
        $this->assertSame(1, $summary['telegram']);
        $this->assertSame(1, $summary['later']);
    }

    public function test_a_broadcast_reaches_only_those_it_may_and_sends_exactly_what_was_written(): void
    {
        $this->fakeChannels();
        $this->telegramBot();
        $this->audienceFixture();

        $broadcast = Broadcasts::for($this->tenant)->queue('New service: TikTok Likes', 'all');
        app(BroadcastSender::class)->run($broadcast);

        $broadcast->refresh();
        $this->assertSame(Broadcast::DONE, $broadcast->status);
        $this->assertSame(2, $broadcast->sent);
        $this->assertSame(1, $broadcast->skipped);

        $this->assertSame(
            'needs_template',
            BroadcastRecipient::withoutTenantScope()->where('customer_phone', '255700000002')->value('reason'),
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains(json_encode($request->data()), 'TikTok Likes')
            && ! str_contains(json_encode($request->data()), 'STOP'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_someone_who_replies_stop_is_left_out_next_time(): void
    {
        $this->fakeChannels();
        $this->customer('255700000001');
        $this->wroteJustNow('255700000001');

        app(BotRouter::class)->route(new InboundMessage($this->number->phone_number_id, '255700000001', 'STOP'));

        $this->assertNotNull(
            BotCustomer::withoutTenantScope()->where('phone', '255700000001')->value('broadcast_opt_out_at'),
        );
        $this->assertSame(0, Audience::for($this->tenant->id)->summary('all')['total']);

        app(BotRouter::class)->route(new InboundMessage($this->number->phone_number_id, '255700000001', 'subscribe'));

        $this->assertSame(1, Audience::for($this->tenant->id)->summary('all')['total']);
    }

    public function test_it_pauses_itself_when_the_channel_keeps_refusing(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 400)]);

        for ($i = 1; $i <= 7; $i++) {
            $this->customer('2557000001'.$i);
            $this->wroteJustNow('2557000001'.$i);
        }

        $broadcast = Broadcasts::for($this->tenant)->queue('Hello', 'all');
        app(BroadcastSender::class)->run($broadcast);

        $broadcast->refresh();
        $this->assertSame(Broadcast::PAUSED, $broadcast->status);
        $this->assertSame(5, $broadcast->failed);
        $this->assertNotNull($broadcast->note);
    }

    public function test_cancelling_leaves_the_rest_unsent(): void
    {
        $this->fakeChannels();
        $this->customer('255700000001');
        $this->wroteJustNow('255700000001');

        $broadcast = Broadcasts::for($this->tenant)->queue('Hello', 'all');
        Broadcasts::for($this->tenant)->cancel($broadcast);

        app(BroadcastSender::class)->run($broadcast->fresh());

        $this->assertSame(Broadcast::CANCELLED, $broadcast->fresh()->status);
        $this->assertSame(0, $broadcast->fresh()->sent);
        Http::assertNothingSent();
    }

    public function test_it_will_not_start_without_an_active_subscription(): void
    {
        $other = Tenant::factory()->create();
        TenantWhatsApp::factory()->create(['tenant_id' => $other->id]);

        $this->assertStringContainsString('subscription', (string) Broadcasts::for($other)->blocker());
        $this->assertNull(Broadcasts::for($this->tenant)->blocker());
    }

    public function test_only_one_broadcast_runs_at_a_time(): void
    {
        $this->customer('255700000001');
        $this->wroteJustNow('255700000001');
        Broadcasts::for($this->tenant)->queue('First', 'all');

        $this->assertStringContainsString('current broadcast', (string) Broadcasts::for($this->tenant)->blocker());
    }

    public function test_starting_one_from_the_page_queues_it(): void
    {
        $this->customer('255700000001');
        $this->wroteJustNow('255700000001');

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('order-bot.broadcast.store'), ['message' => 'Big news', 'audience' => 'all'])
            ->assertSessionHas('success');

        $this->assertSame(1, Broadcast::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_page_opens(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('order-bot.broadcast'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('OrderBot/Broadcast')->where('channels.whatsapp', true));
    }

    public function test_another_resellers_broadcast_cannot_be_cancelled(): void
    {
        $other = Tenant::factory()->create();
        $broadcast = Broadcast::withoutTenantScope()->create([
            'tenant_id' => $other->id,
            'message' => 'Theirs',
            'audience' => 'all',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('order-bot.broadcast.cancel', $broadcast))
            ->assertNotFound();
    }
}
