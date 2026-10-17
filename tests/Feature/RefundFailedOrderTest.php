<?php

namespace Tests\Feature;

use App\Jobs\NotifyOrderRefunded;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Orders\OrderActions;
use App\Services\Orders\RefundOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The reseller's Refund button on an order that failed.
 *
 * The case it exists for: the panel refused the order, so it never reached the
 * provider, the customer was charged, and nothing came back. Pressing the
 * button returns the money to their wallet and tells them — once.
 */
class RefundFailedOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private BotCustomer $customer;

    private TenantPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::factory()->create();
        $this->panel = TenantPanel::factory()->for($this->tenant)->create();
        $this->customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => '0.00']);
    }

    /** The order from the field: panel said the provider is disabled, nothing refunded. */
    private function failedOrder(array $attributes = []): BotOrder
    {
        return BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'customer_phone' => $this->customer->phone,
            'panel_id' => $this->panel->id,
            'provider_order_id' => null,
            'quantity' => 1000,
            'amount' => '1.14',
            'payment_status' => 'paid',
            'status' => 'Failed',
            'order_error' => 'Upstream provider is disabled.',
            ...$attributes,
        ]);
    }

    private function press(BotOrder $order)
    {
        return $this->actingAs($this->tenant, 'tenant')
            ->post(route('orders.act', $order->id), ['action' => 'refund']);
    }

    public function test_a_failed_order_offers_a_refund_and_a_resend(): void
    {
        $actions = OrderActions::availableFor($this->failedOrder());

        $this->assertContains(OrderActions::REFUND, $actions);
        $this->assertContains(OrderActions::RETRY, $actions);
    }

    public function test_pressing_refund_returns_the_money_and_tells_the_customer(): void
    {
        $order = $this->failedOrder();

        $this->press($order)->assertSessionHas('success');

        $this->assertSame('1.14', (string) $this->customer->fresh()->balance);
        $this->assertSame('1.14', (string) $order->fresh()->refunded_amount);
        $this->assertSame('Refunded', $order->fresh()->status);
        Queue::assertPushed(
            NotifyOrderRefunded::class,
            fn ($job) => $job->kind === RefundOrder::FAILED && $job->amount === '1.14' && $job->orderId === $order->id,
        );
    }

    public function test_pressing_it_twice_returns_nothing_the_second_time(): void
    {
        $order = $this->failedOrder();

        $this->press($order);
        $this->press($order)->assertSessionHas('error');

        $this->assertSame('1.14', (string) $this->customer->fresh()->balance);
        Queue::assertPushed(NotifyOrderRefunded::class, 1);
    }

    public function test_a_refunded_order_can_no_longer_be_sent_to_the_panel(): void
    {
        $order = $this->failedOrder();
        $this->press($order);

        $actions = OrderActions::availableFor($order->fresh());

        $this->assertNotContains(OrderActions::RETRY, $actions);
        $this->assertNotContains(OrderActions::REFUND, $actions);
    }

    public function test_it_works_even_with_automatic_refunds_switched_off(): void
    {
        \App\Services\Bots\BotSettings::save($this->tenant->id, 'order', ['shop' => ['auto_refund' => false]]);
        $order = $this->failedOrder();

        $this->press($order)->assertSessionHas('success');

        $this->assertSame('1.14', (string) $this->customer->fresh()->balance);
    }

    public function test_an_order_that_is_not_failed_has_no_refund_button(): void
    {
        foreach (['Pending', 'In progress', 'Completed'] as $status) {
            $order = $this->failedOrder(['status' => $status]);

            $this->assertNotContains(OrderActions::REFUND, OrderActions::availableFor($order), $status);
        }
    }

    public function test_an_unpaid_order_has_no_refund_button(): void
    {
        $order = $this->failedOrder(['payment_status' => 'pending']);

        $this->assertNotContains(OrderActions::REFUND, OrderActions::availableFor($order));
    }

    public function test_the_server_refuses_a_refund_the_button_would_not_offer(): void
    {
        $order = $this->failedOrder(['status' => 'Completed']);

        $this->press($order)->assertSessionHas('error');

        $this->assertSame('0.00', (string) $this->customer->fresh()->balance);
    }

    public function test_a_partial_order_is_left_to_the_automatic_path(): void
    {
        $order = $this->failedOrder(['status' => 'Partial', 'provider_order_id' => '48220', 'remains' => 250]);

        $this->assertNotContains(OrderActions::REFUND, OrderActions::availableFor($order));
    }

    public function test_an_order_already_refunded_in_full_has_no_button(): void
    {
        $order = $this->failedOrder(['status' => 'Canceled', 'provider_order_id' => '48220', 'refunded_amount' => '1.14']);

        $this->assertNotContains(OrderActions::REFUND, OrderActions::availableFor($order));
    }

    public function test_another_resellers_order_cannot_be_refunded(): void
    {
        $other = Tenant::factory()->create();
        $order = $this->failedOrder();

        $this->actingAs($other, 'tenant')
            ->post(route('orders.act', $order->id), ['action' => 'refund'])
            ->assertNotFound();

        $this->assertSame('0.00', (string) $this->customer->fresh()->balance);
    }

    public function test_the_bulk_refund_skips_what_does_not_apply(): void
    {
        $failed = $this->failedOrder();
        $done = $this->failedOrder(['status' => 'Completed']);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('orders.bulk'), ['action' => 'refund', 'ids' => [$failed->id, $done->id]])
            ->assertSessionHas('success');

        $this->assertSame('Refunded', $failed->fresh()->status);
        $this->assertSame('Completed', $done->fresh()->status);
        $this->assertSame('1.14', (string) $this->customer->fresh()->balance);
    }
}
