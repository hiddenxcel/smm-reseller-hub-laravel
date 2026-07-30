<?php

namespace Tests\Feature;

use App\Actions\Payments\CompleteTopup;
use App\Jobs\SubmitOrderToPanel;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Services\Bots\Order\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What happens when a gateway says the money arrived.
 *
 * Gateways retry webhooks, sometimes for hours, so the headline requirement
 * is that calling this twice with the same payment credits the wallet once.
 */
class CompleteTopupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private BotCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::factory()->create();
        $this->customer = BotCustomer::factory()->for($this->tenant)->create([
            'phone' => '255700000001',
            'balance' => '0.00',
        ]);
    }

    private function payment(string $amount = '5.00'): BotPayment
    {
        return BotPayment::factory()->for($this->tenant)->create([
            'customer_id' => $this->customer->id,
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    private function complete(BotPayment $payment): void
    {
        app(CompleteTopup::class)->handle($payment);
    }

    /** Put the customer mid-order, waiting on payment. */
    private function pendingOrderWorth(string $amount): void
    {
        BotConversation::put($this->tenant->id, $this->customer->phone, 'order',
            OrderState::AwaitingPayment->value, [
                'service' => [
                    'panel_id' => null,
                    'provider_service_id' => '1234',
                    'name' => 'IG Followers',
                ],
                'link' => 'https://instagram.com/someone',
                'quantity' => 500,
                'amount' => $amount,
            ]);
    }

    // ---- crediting --------------------------------------------------------

    public function test_it_credits_the_wallet(): void
    {
        $this->complete($this->payment('5.00'));

        $this->assertSame('5.00', (string) $this->customer->fresh()->balance);
    }

    public function test_it_marks_the_payment_successful(): void
    {
        $payment = $this->payment();

        $this->complete($payment);

        $this->assertSame('success', $payment->fresh()->status);
    }

    public function test_a_retried_webhook_credits_only_once(): void
    {
        // The reason markSuccess is a compare-and-swap.
        $payment = $this->payment('5.00');

        $this->complete($payment);
        $this->complete($payment->fresh());
        $this->complete($payment->fresh());

        $this->assertSame('5.00', (string) $this->customer->fresh()->balance);
    }

    public function test_an_already_successful_payment_is_ignored(): void
    {
        $payment = BotPayment::factory()->for($this->tenant)->succeeded()->create([
            'customer_id' => $this->customer->id,
            'amount' => '5.00',
        ]);

        $this->complete($payment);

        $this->assertSame('0.00', (string) $this->customer->fresh()->balance);
    }

    // ---- finishing a pending order ----------------------------------------

    public function test_paying_completes_the_order_the_customer_was_making(): void
    {
        $this->pendingOrderWorth('4.00');

        $this->complete($this->payment('5.00'));

        $this->assertDatabaseHas('bot_orders', [
            'tenant_id' => $this->tenant->id,
            'customer_phone' => $this->customer->phone,
            'service_name' => 'IG Followers',
            'amount' => '4.00',
            'paid_from' => 'wallet',
        ]);

        // Credited 5.00, spent 4.00.
        $this->assertSame('1.00', (string) $this->customer->fresh()->balance);
        Queue::assertPushed(SubmitOrderToPanel::class);
    }

    public function test_the_conversation_is_cleared_once_the_order_is_placed(): void
    {
        $this->pendingOrderWorth('4.00');

        $this->complete($this->payment('5.00'));

        $this->assertNull(
            BotConversation::current($this->tenant->id, $this->customer->phone, 'order')
        );
    }

    public function test_a_standalone_top_up_just_leaves_the_money_in_the_wallet(): void
    {
        // No conversation at all — the customer chose "add funds" from the menu.
        $this->complete($this->payment('5.00'));

        $this->assertSame('5.00', (string) $this->customer->fresh()->balance);
        $this->assertDatabaseCount('bot_orders', 0);
        Queue::assertNotPushed(SubmitOrderToPanel::class);
    }

    public function test_a_partial_top_up_leaves_the_funds_and_places_nothing(): void
    {
        // They owe 10.00 but only paid 5.00.
        $this->pendingOrderWorth('10.00');

        $this->complete($this->payment('5.00'));

        $this->assertSame('5.00', (string) $this->customer->fresh()->balance);
        $this->assertDatabaseCount('bot_orders', 0);
        Queue::assertNotPushed(SubmitOrderToPanel::class);
    }

    public function test_a_top_up_paid_while_not_awaiting_payment_places_nothing(): void
    {
        // Mid-flow but not at the paying step — nothing to complete.
        BotConversation::put($this->tenant->id, $this->customer->phone, 'order',
            OrderState::SelectService->value, []);

        $this->complete($this->payment('5.00'));

        $this->assertSame('5.00', (string) $this->customer->fresh()->balance);
        $this->assertDatabaseCount('bot_orders', 0);
    }

    public function test_a_retry_does_not_place_the_order_twice(): void
    {
        $this->pendingOrderWorth('4.00');
        $payment = $this->payment('5.00');

        $this->complete($payment);
        $this->complete($payment->fresh());

        $this->assertDatabaseCount('bot_orders', 1);
        $this->assertSame('1.00', (string) $this->customer->fresh()->balance);
    }

    // ---- edge cases -------------------------------------------------------

    public function test_a_payment_for_a_deleted_customer_is_survivable(): void
    {
        $payment = $this->payment();
        $this->customer->delete();

        $this->complete($payment->fresh());

        // Marked done rather than retried forever against a customer that
        // no longer exists.
        $this->assertSame('success', $payment->fresh()->status);
    }

    public function test_it_credits_the_right_customer_only(): void
    {
        $other = BotCustomer::factory()->for($this->tenant)->create([
            'phone' => '255700000002',
            'balance' => '0.00',
        ]);

        $this->complete($this->payment('5.00'));

        $this->assertSame('5.00', (string) $this->customer->fresh()->balance);
        $this->assertSame('0.00', (string) $other->fresh()->balance);
    }
}
