<?php

namespace Tests\Feature;

use App\Actions\Payments\CompleteTopup;
use App\Jobs\NotifyPaymentCredited;
use App\Jobs\SubmitOrderToPanel;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotMessengerFactory;
use App\Services\Bots\Order\OrderState;
use App\Services\Payments\PaymentOutcome;
use App\Services\Payments\ReconcilePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBotMessengerFactory;
use Tests\TestCase;

/**
 * A customer who has paid is credited — whether or not the gateway's webhook
 * ever arrives — and told so, and the order they were paying for goes through.
 *
 * Nothing here may lose money: a payment is credited once and only once, the
 * wallet and the payment's status change together or not at all, and an order
 * is placed from what the payment remembers, not from a conversation that may
 * be long gone.
 */
class ReconcilePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private BotCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
        $this->customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => '1.00']);

        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'gateway' => 'fimipay_ng',
            'api_key_enc' => 'sk_test_x',
            'status' => 'active',
        ]);
    }

    private function payment(array $attributes = []): BotPayment
    {
        $ref = 'tu_'.strtolower(\Illuminate\Support\Str::random(20));

        // Timestamps are not mass-assignable, so a payment's age is set after.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        DB::statement('PRAGMA defer_foreign_keys = ON');

        $payment = BotPayment::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'wallet_topup',
            'customer_id' => $this->customer->id,
            'gateway' => 'fimipay_ng',
            'transaction_ref' => $ref,
            'gateway_reference' => $ref,
            'amount' => '5.00',
            'status' => 'pending',
            ...$attributes,
        ]);

        if ($createdAt !== null) {
            $payment->forceFill(['created_at' => $createdAt])->save();
        }

        return $payment;
    }

    /** The gateway's answer to "was this paid?". */
    private function gatewayReports(string $status): void
    {
        Http::fake(['fimipay.com/*' => Http::response(['data' => ['payment_status' => $status]])]);
    }

    private function reconcile(): array
    {
        return app(ReconcilePayments::class)->run();
    }

    private function balance(): string
    {
        return (string) $this->customer->fresh()->balance;
    }

    private function pendingOrder(): array
    {
        return [
            'service' => [
                'panel_id' => null,
                'provider_service_id' => '1234',
                'name' => 'Instagram Followers',
                'cost_price' => '1.00',
            ],
            'link' => 'https://instagram.com/someone',
            'quantity' => 1000,
            'amount' => '5.00',
        ];
    }

    // ---- noticing a payment the webhook never reported -------------------

    public function test_a_payment_the_gateway_says_is_paid_is_credited_without_any_webhook(): void
    {
        $payment = $this->payment();
        $this->gatewayReports('SUCCESS');

        $result = $this->reconcile();

        $this->assertSame(['checked' => 1, 'credited' => 1], $result);
        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame('6.00', $this->balance());
    }

    public function test_the_customer_is_told_the_money_arrived(): void
    {
        $payment = $this->payment();
        $this->gatewayReports('SUCCESS');

        $this->reconcile();

        Queue::assertPushed(NotifyPaymentCredited::class, fn ($job) => $job->paymentId === $payment->id && $job->orderId === null);
    }

    public function test_a_payment_not_yet_paid_is_left_alone_and_asked_about_again_later(): void
    {
        $payment = $this->payment();
        $this->gatewayReports('PENDING');

        $result = $this->reconcile();

        $this->assertSame(0, $result['credited']);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('1.00', $this->balance());
        $this->assertNotNull($payment->fresh()->last_checked_at);
    }

    public function test_a_payment_the_gateway_calls_failed_is_not_given_up_on(): void
    {
        // A hosted page lets a customer fail once and then pay: not final.
        $payment = $this->payment();
        $this->gatewayReports('REJECTED');

        $this->reconcile();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_gateway_that_cannot_be_reached_credits_nothing(): void
    {
        $payment = $this->payment();
        Http::fake(['fimipay.com/*' => Http::response('down', 502)]);

        $this->reconcile();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('1.00', $this->balance());
    }

    // ---- never twice -----------------------------------------------------

    public function test_a_payment_is_credited_once_however_many_times_it_is_noticed(): void
    {
        $payment = $this->payment();
        $this->gatewayReports('SUCCESS');

        $this->reconcile();
        $this->reconcile();
        app(CompleteTopup::class)->handle($payment->fresh());
        app(CompleteTopup::class)->handle($payment->fresh());

        $this->assertSame('6.00', $this->balance());
        Queue::assertPushed(NotifyPaymentCredited::class, 1);
    }

    public function test_a_payment_the_webhook_already_credited_is_not_asked_about(): void
    {
        $payment = $this->payment();
        app(CompleteTopup::class)->handle($payment);

        Http::fake();
        $this->reconcile();

        Http::assertNothingSent();
        $this->assertSame('6.00', $this->balance());
    }

    // ---- the order they were paying for ----------------------------------

    public function test_the_order_is_placed_from_the_payment_even_with_no_conversation(): void
    {
        // The customer said "hi" while waiting, or the session timed out.
        $payment = $this->payment(['pending_order' => $this->pendingOrder()]);
        $this->assertNull(BotConversation::current($this->tenant->id, $this->customer->phone, 'order'));
        $this->gatewayReports('SUCCESS');

        $this->reconcile();

        $order = BotOrder::withoutTenantScope()->where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('Instagram Followers', $order->service_name);
        $this->assertSame(1000, $order->quantity);
        // 1.00 + 5.00 paid - 5.00 order
        $this->assertSame('1.00', $this->balance());
        Queue::assertPushed(SubmitOrderToPanel::class, fn ($job) => $job->orderId === $order->id);
        Queue::assertPushed(NotifyPaymentCredited::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_a_top_up_with_no_order_attached_only_credits_the_wallet(): void
    {
        $this->payment();
        $this->gatewayReports('SUCCESS');

        $this->reconcile();

        $this->assertSame(0, BotOrder::withoutTenantScope()->count());
        Queue::assertNotPushed(SubmitOrderToPanel::class);
    }

    public function test_a_payment_too_small_for_the_order_keeps_the_money_and_places_nothing(): void
    {
        $order = [...$this->pendingOrder(), 'amount' => '50.00'];
        $payment = $this->payment(['pending_order' => $order]);
        $this->gatewayReports('SUCCESS');

        $this->reconcile();

        $this->assertSame('6.00', $this->balance());
        $this->assertSame(0, BotOrder::withoutTenantScope()->count());
        Queue::assertPushed(NotifyPaymentCredited::class, fn ($job) => $job->orderId === null);
        $this->assertSame('success', $payment->fresh()->status);
    }

    public function test_the_conversation_that_was_waiting_is_cleared_once_the_order_is_placed(): void
    {
        BotConversation::put($this->tenant->id, $this->customer->phone, 'order', OrderState::AwaitingPayment->value, ['x' => 1]);
        $this->payment(['pending_order' => $this->pendingOrder()]);
        $this->gatewayReports('SUCCESS');

        $this->reconcile();

        $this->assertNull(BotConversation::current($this->tenant->id, $this->customer->phone, 'order'));
    }

    public function test_a_conversation_that_has_moved_on_is_left_alone(): void
    {
        BotConversation::put($this->tenant->id, $this->customer->phone, 'order', OrderState::SelectPlatform->value, []);
        $this->payment(['pending_order' => $this->pendingOrder()]);
        $this->gatewayReports('SUCCESS');

        $this->reconcile();

        $this->assertSame(
            OrderState::SelectPlatform->value,
            BotConversation::current($this->tenant->id, $this->customer->phone, 'order')?->state,
        );
    }

    // ---- no money lost ---------------------------------------------------

    public function test_if_crediting_fails_the_payment_is_not_marked_paid(): void
    {
        $payment = $this->payment();

        // The wallet update runs, then blows up: the whole step must roll back.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'bot_customers') && str_contains(strtolower($query->sql), 'set "balance"')) {
                throw new \RuntimeException('database went away');
            }
        });

        try {
            app(CompleteTopup::class)->handle($payment);
            $this->fail('The failure should have surfaced.');
        } catch (\RuntimeException) {
            // expected
        }

        // Still pending, so it can be credited properly on the next attempt.
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('1.00', $this->balance());
    }

    public function test_a_payment_for_a_customer_that_is_gone_is_settled_and_logged_not_retried_for_ever(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $payment = $this->payment(['customer_id' => 999999]);

        app(CompleteTopup::class)->handle($payment);

        $this->assertSame('success', $payment->fresh()->status);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once();
        Queue::assertNotPushed(NotifyPaymentCredited::class);
    }

    // ---- when it asks ----------------------------------------------------

    public function test_a_fresh_payment_is_asked_about_every_minute_but_not_faster(): void
    {
        $this->payment(['last_checked_at' => now()->subSeconds(20)]);
        $this->gatewayReports('PENDING');

        $this->assertSame(0, $this->reconcile()['checked']);

        BotPayment::withoutTenantScope()->update(['last_checked_at' => now()->subMinutes(2)]);

        $this->assertSame(1, $this->reconcile()['checked']);
    }

    public function test_an_older_payment_is_asked_about_less_often(): void
    {
        $this->payment(['created_at' => now()->subHours(2), 'last_checked_at' => now()->subMinutes(5)]);
        $this->gatewayReports('PENDING');

        $this->assertSame(0, $this->reconcile()['checked']);

        BotPayment::withoutTenantScope()->update(['last_checked_at' => now()->subMinutes(11)]);

        $this->assertSame(1, $this->reconcile()['checked']);
    }

    public function test_a_payment_older_than_two_days_is_no_longer_asked_about(): void
    {
        $this->payment(['created_at' => now()->subHours(49)]);
        Http::fake();

        $this->assertSame(0, $this->reconcile()['checked']);
        Http::assertNothingSent();
    }

    public function test_a_gateway_that_cannot_be_looked_up_is_skipped(): void
    {
        TenantPaymentGateway::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id, 'gateway' => 'pesapal', 'api_key_enc' => 'k', 'webhook_secret_enc' => 's', 'status' => 'active',
        ]);
        $this->payment(['gateway' => 'pesapal']);
        $this->payment(['gateway' => 'binance']);
        Http::fake();

        $this->reconcile();

        Http::assertNothingSent();
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->payment();
        $this->gatewayReports('SUCCESS');

        $this->artisan('payments:reconcile')->expectsOutput('Asked about 1, credited 1.')->assertSuccessful();
    }

    // ---- reading each gateway's word for "paid" ---------------------------

    public function test_each_gateways_word_for_paid_is_read_correctly(): void
    {
        $this->assertTrue(PaymentOutcome::isPaid('paystack', 'success'));
        $this->assertTrue(PaymentOutcome::isPaid('razorpay', 'paid'));
        $this->assertTrue(PaymentOutcome::isPaid('nowpayments', 'finished'));
        $this->assertTrue(PaymentOutcome::isPaid('snippe_ke', 'completed'));
        $this->assertTrue(PaymentOutcome::isPaid('payu', 'completed'));
    }

    public function test_anything_that_is_not_clearly_paid_is_not_paid(): void
    {
        $this->assertFalse(PaymentOutcome::isPaid('nowpayments', 'confirmed')); // paid, but funds not yet settled
        $this->assertFalse(PaymentOutcome::isPaid('paystack', 'abandoned'));
        $this->assertFalse(PaymentOutcome::isPaid('razorpay', 'created'));
        $this->assertFalse(PaymentOutcome::isPaid('payu', 'pending'));
        $this->assertFalse(PaymentOutcome::isPaid('snippe', 'something-new'));
        $this->assertFalse(PaymentOutcome::isPaid('snippe', null));
        $this->assertFalse(PaymentOutcome::isPaid('snippe', ''));
    }

    public function test_paystack_and_razorpay_are_asked_by_our_reference_and_the_rest_by_the_gateways(): void
    {
        $this->assertSame('tu_ours', PaymentOutcome::referenceFor('paystack', 'tu_ours', 'theirs'));
        $this->assertSame('tu_ours', PaymentOutcome::referenceFor('razorpay', 'tu_ours', 'theirs'));
        $this->assertSame('theirs', PaymentOutcome::referenceFor('snippe', 'tu_ours', 'theirs'));
        $this->assertSame('tu_ours', PaymentOutcome::referenceFor('snippe', 'tu_ours', null));
    }

    // ---- the message -----------------------------------------------------

    private function notify(BotPayment $payment, ?int $orderId = null): FakeBotMessengerFactory
    {
        $factory = new FakeBotMessengerFactory;
        $this->app->instance(BotMessengerFactory::class, $factory);
        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order', 'status' => 'active']);

        (new NotifyPaymentCredited($payment->id, $orderId))->handle($factory);

        return $factory;
    }

    public function test_the_message_says_how_much_arrived_and_the_new_balance(): void
    {
        $payment = $this->payment();
        $this->customer->credit('5.00');

        $sent = $this->notify($payment)->messenger->sent;

        $this->assertCount(1, $sent);
        $this->assertSame($this->customer->phone, $sent[0]['to']);
        $this->assertStringContainsString('USD 5.00', $sent[0]['body']);
        $this->assertStringContainsString('USD 6.00', $sent[0]['body']);
    }

    public function test_the_message_names_the_order_that_was_placed(): void
    {
        $payment = $this->payment(['pending_order' => $this->pendingOrder()]);
        $order = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'service_name' => 'Instagram Followers',
        ]);

        $body = $this->notify($payment, $order->id)->messenger->sent[0]['body'];

        $this->assertStringContainsString('#'.$order->id, $body);
        $this->assertStringContainsString('Instagram Followers', $body);
    }

    public function test_the_message_says_so_when_the_order_could_not_be_placed(): void
    {
        $payment = $this->payment(['pending_order' => $this->pendingOrder()]);

        $body = $this->notify($payment, null)->messenger->sent[0]['body'];

        $this->assertStringContainsString('could not be placed', $body);
    }

    public function test_no_number_to_message_from_is_not_an_error(): void
    {
        $payment = $this->payment();
        $factory = new FakeBotMessengerFactory;

        (new NotifyPaymentCredited($payment->id))->handle($factory);

        $this->assertSame([], $factory->messenger->sent);
    }
}
