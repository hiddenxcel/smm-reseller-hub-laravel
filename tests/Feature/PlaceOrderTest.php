<?php

namespace Tests\Feature;

use App\Actions\Orders\PlaceOrder;
use App\Actions\Orders\PlaceOrderFailure;
use App\Models\BotCustomer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The bug this action exists to fix: the old code debited the wallet and then
 * created the order with no transaction, so a failure in between charged the
 * customer for an order that never existed.
 */
class PlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    private function service(?int $panelId = null): array
    {
        return [
            'panel_id' => $panelId,
            'provider_service_id' => '1234',
            'name' => 'Instagram Followers',
        ];
    }

    public function test_it_charges_the_wallet_and_records_the_order(): void
    {
        $customer = BotCustomer::factory()->withBalance('10.00')->create();

        $result = (new PlaceOrder)->handle($customer, $this->service(), 'https://insta.gr/x', 500, '2.50');

        $this->assertTrue($result->placed);
        $this->assertSame('7.50', (string) $customer->fresh()->balance);

        $this->assertDatabaseHas('bot_orders', [
            'id' => $result->order->id,
            'customer_id' => $customer->id,
            'amount' => '2.50',
            'payment_status' => 'paid',
            'paid_from' => 'wallet',
            'status' => 'pending',
        ]);
    }

    public function test_it_refuses_when_the_wallet_is_short_and_charges_nothing(): void
    {
        $customer = BotCustomer::factory()->withBalance('1.00')->create();

        $result = (new PlaceOrder)->handle($customer, $this->service(), 'https://insta.gr/x', 500, '2.50');

        $this->assertFalse($result->placed);
        $this->assertSame(PlaceOrderFailure::InsufficientFunds, $result->failure);
        $this->assertSame('1.00', (string) $customer->fresh()->balance);
        $this->assertDatabaseCount('bot_orders', 0);
    }

    public function test_an_exact_balance_is_enough(): void
    {
        $customer = BotCustomer::factory()->withBalance('2.50')->create();

        $result = (new PlaceOrder)->handle($customer, $this->service(), 'https://insta.gr/x', 500, '2.50');

        $this->assertTrue($result->placed);
        $this->assertSame('0.00', (string) $customer->fresh()->balance);
    }

    public function test_the_debit_is_rolled_back_when_recording_the_order_fails(): void
    {
        // This is the regression the action exists for. Force the insert to
        // fail after the debit has already been applied, and assert the money
        // came back.
        $customer = BotCustomer::factory()->withBalance('10.00')->create();

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into "bot_orders"')) {
                throw new \RuntimeException('simulated failure recording the order');
            }
        });

        try {
            (new PlaceOrder)->handle($customer, $this->service(), 'https://insta.gr/x', 500, '2.50');
            $this->fail('expected the simulated failure to propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('10.00', (string) $customer->fresh()->balance, 'the debit must be rolled back');
        $this->assertSame('0.00', (string) $customer->fresh()->total_spent);
        $this->assertDatabaseCount('bot_orders', 0);
    }

    public function test_it_records_the_panel_the_service_came_from(): void
    {
        $customer = BotCustomer::factory()->withBalance('10.00')->create();

        $result = (new PlaceOrder)->handle($customer, $this->service(), 'https://insta.gr/x', 500, '2.50');

        $this->assertNull($result->order->panel_id, 'a service with no panel records none');
        $this->assertNull($result->order->provider_order_id, 'not yet submitted to any panel');
    }

    public function test_the_order_belongs_to_the_customers_tenant(): void
    {
        $alice = Tenant::factory()->create();
        $customer = BotCustomer::factory()->for($alice)->withBalance('10.00')->create();

        $result = (new PlaceOrder)->handle($customer, $this->service(), 'https://insta.gr/x', 500, '2.50');

        $this->assertSame($alice->id, $result->order->tenant_id);
    }

    public function test_two_orders_cannot_overdraw_the_same_wallet(): void
    {
        $customer = BotCustomer::factory()->withBalance('4.00')->create();
        $action = new PlaceOrder;

        $first = $action->handle($customer, $this->service(), 'https://insta.gr/a', 100, '2.50');
        $second = $action->handle($customer, $this->service(), 'https://insta.gr/b', 100, '2.50');

        $this->assertTrue($first->placed);
        $this->assertFalse($second->placed, '4.00 covers one 2.50 order, not two');
        $this->assertSame('1.50', (string) $customer->fresh()->balance);
        $this->assertDatabaseCount('bot_orders', 1);
    }

    public function test_it_compares_money_exactly_not_as_floats(): void
    {
        // 0.1 + 0.2 style drift must not let an order through, or block one.
        $customer = BotCustomer::factory()->withBalance('0.30')->create();

        $result = (new PlaceOrder)->handle($customer, $this->service(), 'https://insta.gr/x', 10, '0.30');

        $this->assertTrue($result->placed);
        $this->assertSame('0.00', (string) $customer->fresh()->balance);
    }
}
