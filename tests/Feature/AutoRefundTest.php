<?php

namespace Tests\Feature;

use App\Jobs\NotifyOrderRefunded;
use App\Jobs\SubmitOrderToPanel;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantWhatsApp;
use App\Services\Api\OrderView;
use App\Services\Bots\BotMessengerFactory;
use App\Services\Bots\BotSettings;
use App\Services\Orders\OrderActions;
use App\Services\Orders\RefundOrder;
use App\Services\Orders\SyncOrderStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBotMessengerFactory;
use Tests\TestCase;

/**
 * Money back for the customer when the provider cannot deliver, and what the
 * customer and the reseller each see when an order never reaches the provider.
 *
 * The line this holds: an order the provider HAS is refunded when it cancels
 * or part-delivers; an order the provider NEVER got is not refunded, stays
 * failed for the reseller to resend, and looks pending to the customer.
 */
class AutoRefundTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantPanel $panel;

    private BotCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
        $this->panel = TenantPanel::factory()->for($this->tenant)->create();
        $this->customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => '10.00']);
    }

    /** An order the provider already has. */
    private function placed(array $attributes = []): BotOrder
    {
        return BotOrder::factory()->submitted('48220')->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'customer_phone' => $this->customer->phone,
            'panel_id' => $this->panel->id,
            'quantity' => 1000,
            'amount' => '4.00',
            ...$attributes,
        ]);
    }

    /** The provider answering a status request for these orders. */
    private function providerSays(array $byOrderId): void
    {
        Http::fake(['*' => Http::response($byOrderId)]);
    }

    private function sync(): array
    {
        return app(SyncOrderStatuses::class)->run();
    }

    private function balance(): string
    {
        return (string) $this->customer->fresh()->balance;
    }

    // ---- cancelled -------------------------------------------------------

    public function test_a_cancelled_order_gives_the_customer_everything_back(): void
    {
        $order = $this->placed();
        $this->providerSays(['48220' => ['status' => 'Canceled', 'remains' => '1000']]);

        $result = $this->sync();

        $this->assertSame(1, $result['refunded']);
        $this->assertSame('14.00', $this->balance());
        $this->assertSame('Canceled', $order->fresh()->status);
        $this->assertSame('4.00', (string) $order->fresh()->refunded_amount);
        $this->assertNotNull($order->fresh()->refunded_at);
        Queue::assertPushed(NotifyOrderRefunded::class, fn ($job) => $job->kind === RefundOrder::CANCELLED && $job->amount === '4.00');
    }

    // ---- partial ---------------------------------------------------------

    public function test_a_partial_order_refunds_only_what_was_not_delivered(): void
    {
        $order = $this->placed(['quantity' => 1000, 'amount' => '4.00']);
        $this->providerSays(['48220' => ['status' => 'Partial', 'remains' => '250']]);

        $this->sync();

        // A quarter was not delivered: a quarter of four dollars.
        $this->assertSame('11.00', $this->balance());
        $this->assertSame('1.00', (string) $order->fresh()->refunded_amount);
        $this->assertSame(250, $order->fresh()->remains);
        Queue::assertPushed(NotifyOrderRefunded::class, fn ($job) => $job->kind === RefundOrder::PARTIAL && $job->amount === '1.00');
    }

    public function test_the_partial_share_is_rounded_to_cents(): void
    {
        $this->placed(['quantity' => 300, 'amount' => '1.00']);
        $this->providerSays(['48220' => ['status' => 'Partial', 'remains' => '100']]);

        $this->sync();

        $this->assertSame('10.33', $this->balance());
    }

    public function test_a_partial_with_no_remaining_count_refunds_nothing_rather_than_guess(): void
    {
        $order = $this->placed();
        $this->providerSays(['48220' => ['status' => 'Partial']]);

        $this->sync();

        $this->assertSame('10.00', $this->balance());
        $this->assertSame('0.00', (string) $order->fresh()->refunded_amount);
    }

    // ---- never twice -----------------------------------------------------

    public function test_running_it_again_refunds_nothing_more(): void
    {
        $order = $this->placed();
        $this->providerSays(['48220' => ['status' => 'Canceled', 'remains' => '1000']]);

        $this->sync();
        $this->sync();
        app(RefundOrder::class)->handle($order->fresh());

        $this->assertSame('14.00', $this->balance());
        Queue::assertPushed(NotifyOrderRefunded::class, 1);
    }

    public function test_a_partial_that_later_becomes_a_cancel_is_only_topped_up(): void
    {
        $order = $this->placed(['quantity' => 1000, 'amount' => '4.00']);

        $this->providerSays(['48220' => ['status' => 'Partial', 'remains' => '250']]);
        $this->sync();
        $this->assertSame('11.00', $this->balance());

        // Cancelled outright afterwards: the other three dollars, not four more.
        $order->fresh()->update(['status' => 'Canceled']);
        app(RefundOrder::class)->handle($order->fresh());

        $this->assertSame('14.00', $this->balance());
        $this->assertSame('4.00', (string) $order->fresh()->refunded_amount);
    }

    // ---- the switch ------------------------------------------------------

    public function test_with_refunds_switched_off_the_status_is_still_recorded_but_no_money_moves(): void
    {
        $settings = BotSettings::for($this->tenant->id, 'order');
        Arr::set($settings, 'shop.auto_refund', false);
        BotSettings::save($this->tenant->id, 'order', $settings);

        $order = $this->placed();
        $this->providerSays(['48220' => ['status' => 'Canceled', 'remains' => '1000']]);

        $this->sync();

        $this->assertSame('Canceled', $order->fresh()->status);
        $this->assertSame('10.00', $this->balance());
        Queue::assertNotPushed(NotifyOrderRefunded::class);
    }

    public function test_refunds_are_on_unless_a_shop_turns_them_off(): void
    {
        $this->assertTrue(RefundOrder::enabledFor($this->tenant->id));
    }

    // ---- what does not refund --------------------------------------------

    public function test_a_completed_order_refunds_nothing(): void
    {
        $order = $this->placed();
        $this->providerSays(['48220' => ['status' => 'Completed', 'remains' => '0']]);

        $this->sync();

        $this->assertSame('Completed', $order->fresh()->status);
        $this->assertSame('10.00', $this->balance());
    }

    public function test_an_order_still_in_progress_is_updated_but_not_refunded(): void
    {
        $order = $this->placed();
        $this->providerSays(['48220' => ['status' => 'In progress', 'remains' => '600']]);

        $this->sync();

        $this->assertSame('In progress', $order->fresh()->status);
        $this->assertSame('10.00', $this->balance());
    }

    public function test_an_order_the_provider_never_received_is_never_refunded(): void
    {
        $order = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'panel_id' => $this->panel->id,
            'provider_order_id' => null,
            'status' => 'Failed',
            'amount' => '4.00',
        ]);

        $this->assertNull(app(RefundOrder::class)->handle($order));
        $this->assertSame('10.00', $this->balance());

        // Nor is it even asked about: there is no provider id to ask with.
        Http::assertNothingSent();
        $this->sync();
        Http::assertNothingSent();
    }

    public function test_finished_orders_are_not_asked_about_again(): void
    {
        $this->placed(['status' => 'Completed']);
        $this->placed(['status' => 'Canceled', 'provider_order_id' => '2']);

        $this->sync();

        Http::assertNothingSent();
    }

    public function test_an_unreachable_panel_leaves_the_order_to_try_again_next_time(): void
    {
        $order = $this->placed();
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $this->sync();

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('10.00', $this->balance());
    }

    public function test_the_provider_is_asked_about_many_orders_in_one_call(): void
    {
        $this->placed(['provider_order_id' => '1']);
        $this->placed(['provider_order_id' => '2']);
        $this->placed(['provider_order_id' => '3']);
        Http::fake(['*' => Http::response([])]);

        $this->sync();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['orders'] === '1,2,3' && $request['action'] === 'status');
    }

    // ---- the reseller cancels it himself ---------------------------------

    public function test_cancelling_an_order_at_the_provider_refunds_the_customer(): void
    {
        $order = $this->placed();
        Http::fake(['*' => Http::response([['order' => '48220', 'cancel' => 1]])]);

        $result = OrderActions::cancel($order);

        $this->assertFalse($result->failed);
        $this->assertSame('14.00', $this->balance());
        $this->assertStringContainsString('4.00', $result->message);
    }

    public function test_a_cancel_the_provider_refuses_refunds_nothing(): void
    {
        $order = $this->placed();
        Http::fake(['*' => Http::response([['cancel' => ['error' => 'Order is already in progress']]])]);

        $result = OrderActions::cancel($order);

        $this->assertTrue($result->failed);
        $this->assertSame('10.00', $this->balance());
    }

    // ---- failing to reach the provider -----------------------------------

    private function unsent(): BotOrder
    {
        return BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'customer_phone' => $this->customer->phone,
            'panel_id' => $this->panel->id,
            'status' => 'pending',
        ]);
    }

    public function test_when_every_attempt_fails_the_order_is_failed_for_the_reseller(): void
    {
        $order = $this->unsent();

        (new SubmitOrderToPanel($order->id))->failed(new \RuntimeException('Panel rejected order: not enough balance'));

        $this->assertSame('Failed', $order->fresh()->status);
        $this->assertStringContainsString('not enough balance', $order->fresh()->order_error);
    }

    public function test_that_failed_order_still_looks_pending_to_the_customer_and_the_api(): void
    {
        $order = $this->unsent();
        (new SubmitOrderToPanel($order->id))->failed(new \RuntimeException('x'));
        $order = $order->fresh();

        $this->assertSame('Pending', $order->customerStatus());
        $this->assertSame('Pending', OrderView::of($order)['status']);
    }

    public function test_once_the_provider_has_it_a_failure_is_shown_as_it_is(): void
    {
        $order = $this->placed(['status' => 'Canceled']);

        $this->assertSame('Canceled', $order->customerStatus());
        $this->assertSame('Canceled', OrderView::of($order)['status']);
    }

    public function test_a_late_failure_does_not_touch_an_order_the_provider_already_has(): void
    {
        $order = $this->placed();

        (new SubmitOrderToPanel($order->id))->failed(new \RuntimeException('late'));

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_the_reseller_can_resend_a_failed_order_and_it_goes_back_to_pending(): void
    {
        $order = $this->unsent();
        $order->update(['status' => 'Failed', 'order_error' => 'Panel rejected order']);

        $this->assertContains(OrderActions::RETRY, OrderActions::availableFor($order->fresh()));

        OrderActions::retry($order->fresh());

        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->order_error);
        Queue::assertPushed(SubmitOrderToPanel::class);
    }

    public function test_an_order_that_has_reached_the_provider_cannot_be_resent(): void
    {
        $order = $this->placed(['status' => 'Canceled']);

        $this->assertNotContains(OrderActions::RETRY, OrderActions::availableFor($order));
    }

    // ---- telling the customer --------------------------------------------

    public function test_the_customer_is_told_on_whatsapp_how_much_came_back(): void
    {
        $factory = new FakeBotMessengerFactory;
        $this->app->instance(BotMessengerFactory::class, $factory);
        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order', 'status' => 'active']);

        $order = $this->placed();
        $this->customer->credit('4.00'); // as the refund itself would have

        (new NotifyOrderRefunded($order->id, RefundOrder::CANCELLED, '4.00'))->handle($factory);

        $this->assertCount(1, $factory->messenger->sent);
        $sent = $factory->messenger->sent[0];
        $this->assertSame($this->customer->phone, $sent['to']);
        $this->assertStringContainsString('USD 4.00', $sent['body']);
        $this->assertStringContainsString('USD 14.00', $sent['body']);
        $this->assertStringContainsString('48220', $sent['body']);
    }

    public function test_a_partial_refund_says_so(): void
    {
        $factory = new FakeBotMessengerFactory;
        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order', 'status' => 'active']);
        $order = $this->placed();

        (new NotifyOrderRefunded($order->id, RefundOrder::PARTIAL, '1.00'))->handle($factory);

        $this->assertStringContainsString('not delivered', $factory->messenger->sent[0]['body']);
    }

    public function test_no_number_to_message_from_is_not_an_error(): void
    {
        $factory = new FakeBotMessengerFactory;
        $order = $this->placed();

        (new NotifyOrderRefunded($order->id, RefundOrder::CANCELLED, '4.00'))->handle($factory);

        $this->assertSame([], $factory->messenger->sent);
    }

    // ---- the switch in Bot setup -----------------------------------------

    private function saveSetup(array $extra = [])
    {
        return $this->actingAs($this->tenant)->post(route('order-bot.setup'), [
            'lang' => 'en',
            'supportMode' => 'admin',
            ...$extra,
        ]);
    }

    public function test_the_owner_can_switch_refunds_off_and_on_in_bot_setup(): void
    {
        $this->saveSetup(['autoRefund' => false])->assertSessionHasNoErrors();
        $this->assertFalse(RefundOrder::enabledFor($this->tenant->id));

        $this->saveSetup(['autoRefund' => true]);
        $this->assertTrue(RefundOrder::enabledFor($this->tenant->id));
    }

    public function test_saving_bot_setup_without_the_field_leaves_the_switch_alone(): void
    {
        $this->saveSetup(['autoRefund' => false]);

        $this->saveSetup();

        $this->assertFalse(RefundOrder::enabledFor($this->tenant->id));
    }

    public function test_the_setup_page_reports_the_switch(): void
    {
        $this->actingAs($this->tenant)
            ->get(route('order-bot', 'setup'))
            ->assertInertia(fn ($page) => $page->where('setup.autoRefund', true));
    }
}
