<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\Plan;
use App\Models\PlatformNumber;
use App\Services\Billing\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * What a reseller is charged.
 *
 * Everything here is integer cents. A discount applied to a float is exactly
 * the arithmetic that leaves a total a cent off from the sum of its lines,
 * and a reseller who adds up their own invoice will notice.
 */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::create([
            'code' => 'order_bot',
            'service_key' => ServiceKey::OrderBot,
            'name' => 'Order Bot',
            'price_monthly' => '20.00',
            'price_yearly' => '192.00',
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    // ---- one service, one term -------------------------------------------

    public function test_a_monthly_term_carries_no_discount(): void
    {
        $this->assertSame(2000, Pricing::serviceTotal('order_bot', 1));
    }

    public function test_longer_terms_are_discounted(): void
    {
        // 20 x 3 = 60, less 10%
        $this->assertSame(5400, Pricing::serviceTotal('order_bot', 3));

        // 20 x 6 = 120, less 15%
        $this->assertSame(10200, Pricing::serviceTotal('order_bot', 6));

        // 20 x 12 = 240, less 25%
        $this->assertSame(18000, Pricing::serviceTotal('order_bot', 12));
    }

    public function test_a_term_we_do_not_sell_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Pricing::serviceTotal('order_bot', 7);
    }

    public function test_a_service_with_no_plan_row_is_not_on_sale(): void
    {
        // Priced at nothing is not the same as free — it means we have not
        // decided yet, so it must not be sellable.
        $this->expectException(InvalidArgumentException::class);

        Pricing::serviceTotal('support_bot', 1);
    }

    public function test_only_priced_services_are_offered(): void
    {
        $offered = array_column(Pricing::sellableServices(), 'key');

        $this->assertContains('order_bot', $offered);
        $this->assertNotContains('support_bot', $offered);
    }

    // ---- rounding --------------------------------------------------------

    public function test_the_total_is_rounded_once_not_per_month(): void
    {
        // 16.67 x 3 = 50.01, less 10% = 45.009 -> 45.01.
        // Discounting each month and summing would give 45.00 and drift
        // further the longer the term.
        Plan::where('code', 'order_bot')->update(['price_monthly' => '16.67']);

        $this->assertSame(4501, Pricing::serviceTotal('order_bot', 3));
    }

    public function test_decimal_prices_survive_conversion_to_cents(): void
    {
        // (int) (20.15 * 100) is 2014 on some platforms — the classic float
        // truncation bug in money code.
        $this->assertSame(2015, Pricing::toCents('20.15'));
        $this->assertSame(2015, Pricing::toCents(20.15));
        $this->assertSame('20.15', Pricing::toAmount(2015));
    }

    // ---- carts -----------------------------------------------------------

    public function test_a_quote_totals_its_lines(): void
    {
        Plan::create([
            'code' => 'support_bot',
            'service_key' => ServiceKey::SupportBot,
            'name' => 'Support Bot',
            'price_monthly' => '15.00',
            'price_yearly' => '144.00',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $quote = Pricing::quote(['order_bot', 'support_bot'], 6);

        $this->assertCount(2, $quote['lines']);
        $this->assertSame(10200, $quote['lines'][0]['amount']);
        $this->assertSame(7650, $quote['lines'][1]['amount']);
        $this->assertSame(17850, $quote['total']);

        // The invoice must add up.
        $this->assertSame(
            array_sum(array_column($quote['lines'], 'amount')),
            $quote['total'],
        );
    }

    public function test_a_number_is_one_time_and_never_discounted(): void
    {
        // Buying twelve months of a bot does not make a phone number cheaper.
        $number = PlatformNumber::factory()->create(['monthly_cost' => '15.00']);

        $quote = Pricing::quote(['order_bot'], 12, $number);

        $numberLine = collect($quote['lines'])->firstWhere('type', 'number');

        $this->assertSame(1500, $numberLine['amount']);
        $this->assertNull($numberLine['months']);
        $this->assertSame(18000 + 1500, $quote['total']);
    }

    public function test_a_quote_can_be_a_number_alone_only_alongside_a_service(): void
    {
        $number = PlatformNumber::factory()->create(['monthly_cost' => '15.00']);

        $quote = Pricing::quote([], 1, $number);

        // Pricing does not enforce the rule — checkout does — but it must
        // still total correctly when asked.
        $this->assertSame(1500, $quote['total']);
    }

    public function test_the_terms_on_offer_come_from_config(): void
    {
        $months = array_column(Pricing::terms(), 'months');

        $this->assertSame([1, 3, 6, 12], $months);
    }
}
