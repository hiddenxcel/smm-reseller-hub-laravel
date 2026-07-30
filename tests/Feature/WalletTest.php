<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wallet arithmetic touches real money. The old code stored balances as
 * floats and debited without a transaction; these tests pin the behaviour we
 * want instead: exact decimals, and a debit that cannot overdraw.
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_debit_reduces_balance_and_records_spend(): void
    {
        $customer = BotCustomer::factory()->withBalance('10.00')->create();

        $this->assertTrue($customer->debit('2.50'));
        $this->assertSame('7.50', (string) $customer->balance);
        $this->assertSame('2.50', (string) $customer->total_spent);
    }

    public function test_debit_of_exactly_the_balance_succeeds(): void
    {
        $customer = BotCustomer::factory()->withBalance('5.00')->create();

        $this->assertTrue($customer->debit('5.00'));
        $this->assertSame('0.00', (string) $customer->balance);
    }

    public function test_debit_beyond_balance_is_refused_and_charges_nothing(): void
    {
        $customer = BotCustomer::factory()->withBalance('1.00')->create();

        $this->assertFalse($customer->debit('1.01'));
        $this->assertSame('1.00', (string) $customer->fresh()->balance);
        $this->assertSame('0.00', (string) $customer->fresh()->total_spent);
    }

    public function test_balance_never_goes_negative_under_repeated_debits(): void
    {
        $customer = BotCustomer::factory()->withBalance('1.00')->create();

        $succeeded = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($customer->debit('0.40')) {
                $succeeded++;
            }
        }

        // 0.40 fits twice into 1.00; the third must be refused.
        $this->assertSame(2, $succeeded);
        $this->assertSame('0.20', (string) $customer->fresh()->balance);
    }

    public function test_credit_adds_funds(): void
    {
        $customer = BotCustomer::factory()->withBalance('1.00')->create();

        $customer->credit('9.00');

        $this->assertSame('10.00', (string) $customer->balance);
    }

    public function test_money_keeps_exact_decimals(): void
    {
        // 0.1 + 0.2 is the classic float trap; NUMERIC must not drift.
        $customer = BotCustomer::factory()->withBalance('0.10')->create();

        $customer->credit('0.20');

        $this->assertSame('0.30', (string) $customer->balance);
    }

    public function test_a_rejected_amount_throws_rather_than_reaching_sql(): void
    {
        $customer = BotCustomer::factory()->withBalance('10.00')->create();

        $this->expectException(\InvalidArgumentException::class);

        $customer->debit("1.00'); DROP TABLE bot_customers; --");
    }

    public function test_debit_is_scoped_to_the_right_customer(): void
    {
        $alice = Tenant::factory()->create();
        $bob = Tenant::factory()->create();

        $alicesCustomer = BotCustomer::factory()->for($alice)->withBalance('10.00')->create();
        $bobsCustomer = BotCustomer::factory()->for($bob)->withBalance('10.00')->create();

        $alicesCustomer->debit('4.00');

        $this->assertSame('6.00', (string) $alicesCustomer->fresh()->balance);
        $this->assertSame('10.00', (string) $bobsCustomer->fresh()->balance);
    }
}
