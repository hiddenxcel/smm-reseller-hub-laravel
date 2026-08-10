<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use App\Services\Dashboard\ProfitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Profit is the figure a reseller prices on, so a wrong one here is worse than
 * no figure at all — it is the number that decides whether a service stays on
 * sale.
 *
 * Like every dashboard aggregate, these are the queries where a missing tenant
 * scope hides: no row is ever displayed, but the total is someone else's.
 */
class ProfitReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function report(): ProfitReport
    {
        return ProfitReport::for($this->tenant->fresh());
    }

    /** An order with both sides of the trade recorded. */
    private function order(string $amount, ?string $charge, array $attributes = []): BotOrder
    {
        return BotOrder::factory()
            ->for($this->tenant)
            ->create([
                'customer_id' => BotCustomer::factory()->for($this->tenant),
                'amount' => $amount,
                'charge' => $charge,
                'payment_status' => 'paid',
                ...$attributes,
            ]);
    }

    // ---- the headline ----------------------------------------------------

    public function test_profit_is_what_was_sold_less_what_the_panel_charged(): void
    {
        $this->order('10.00', '6.00');
        $this->order('5.00', '2.00');

        $summary = $this->report()->summary();

        $this->assertSame(15.0, $summary['revenue']);
        $this->assertSame(8.0, $summary['cost']);
        $this->assertSame(7.0, $summary['profit']);
        $this->assertSame(46.7, $summary['margin']);
    }

    public function test_a_service_sold_below_cost_reports_a_loss(): void
    {
        $this->order('2.00', '5.00');

        $summary = $this->report()->summary();

        $this->assertSame(-3.0, $summary['profit']);
    }

    /**
     * An unpaid order cost nothing and earned nothing. Counting its would-be
     * margin would show profit on money that never arrived.
     */
    public function test_unpaid_orders_are_excluded(): void
    {
        $this->order('10.00', '6.00', ['payment_status' => 'pending']);
        $this->order('10.00', '6.00', ['payment_status' => 'failed']);

        $summary = $this->report()->summary();

        $this->assertSame(0.0, $summary['profit']);
        $this->assertSame(0, $summary['measuredOrders']);
    }

    /**
     * Orders placed before cost snapshotting existed carry no charge. They are
     * excluded rather than counted as pure profit, which would be a large and
     * flattering lie.
     */
    public function test_orders_without_a_recorded_cost_are_excluded_not_counted_as_free(): void
    {
        $this->order('10.00', '6.00');
        $this->order('50.00', null);

        $summary = $this->report()->summary();

        $this->assertSame(10.0, $summary['revenue']);
        $this->assertSame(4.0, $summary['profit']);
    }

    /** A margin drawn from a fraction of the orders has to say so. */
    public function test_coverage_reports_how_much_of_the_period_could_be_measured(): void
    {
        $this->order('10.00', '6.00');
        $this->order('10.00', null);
        $this->order('10.00', null);
        $this->order('10.00', null);

        $summary = $this->report()->summary();

        $this->assertSame(1, $summary['measuredOrders']);
        $this->assertSame(4, $summary['totalOrders']);
        $this->assertSame(25, $summary['coverage']);
    }

    public function test_orders_outside_the_window_are_excluded(): void
    {
        $this->order('10.00', '6.00');
        $this->order('99.00', '1.00', ['created_at' => Carbon::now()->subDays(45)]);

        $summary = $this->report()->summary();

        $this->assertSame(10.0, $summary['revenue']);
        $this->assertSame(4.0, $summary['profit']);
    }

    /** "Up 300% from a loss" is arithmetic, not information. */
    public function test_no_delta_is_reported_when_the_prior_period_lost_money(): void
    {
        $this->order('10.00', '6.00');
        $this->order('1.00', '9.00', ['created_at' => Carbon::now()->subDays(40)]);

        $this->assertNull($this->report()->summary()['delta']);
    }

    // ---- isolation -------------------------------------------------------

    public function test_another_resellers_orders_are_never_counted(): void
    {
        $other = Tenant::factory()->create();

        BotOrder::factory()
            ->for($other)
            ->create([
                'customer_id' => BotCustomer::factory()->for($other),
                'amount' => '999.00',
                'charge' => '1.00',
                'payment_status' => 'paid',
            ]);

        $this->order('10.00', '6.00');

        $summary = $this->report()->summary();

        $this->assertSame(10.0, $summary['revenue']);
        $this->assertSame(4.0, $summary['profit']);
    }

    // ---- per service -----------------------------------------------------

    public function test_services_are_listed_thinnest_margin_first(): void
    {
        $this->order('10.00', '2.00', ['service_name' => 'Fat margin']);
        $this->order('10.00', '9.50', ['service_name' => 'Thin margin']);

        $rows = $this->report()->byService();

        $this->assertSame('Thin margin', $rows[0]['name']);
        $this->assertSame(0.5, $rows[0]['profit']);
        $this->assertSame('Fat margin', $rows[1]['name']);
    }

    public function test_orders_of_the_same_service_are_summed(): void
    {
        $this->order('10.00', '6.00', ['service_name' => 'Instagram Followers']);
        $this->order('20.00', '11.00', ['service_name' => 'Instagram Followers']);

        $rows = $this->report()->byService();

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['orders']);
        $this->assertSame(30.0, $rows[0]['revenue']);
        $this->assertSame(13.0, $rows[0]['profit']);
    }

    // ---- priced underwater -----------------------------------------------

    /**
     * Read from the catalogue rather than from orders: a service priced below
     * cost that nobody has bought yet is exactly the one worth catching, and
     * it has no order history to appear in.
     */
    public function test_services_priced_at_or_below_cost_are_flagged_before_anyone_buys_one(): void
    {
        BotService::factory()->for($this->tenant)->create([
            'name' => 'Underwater',
            'cost_price' => '3.0000',
            'my_price' => '2.0000',
        ]);

        BotService::factory()->for($this->tenant)->create([
            'name' => 'Healthy',
            'cost_price' => '1.0000',
            'my_price' => '4.0000',
        ]);

        $flagged = $this->report()->underwater();

        $this->assertCount(1, $flagged);
        $this->assertSame('Underwater', $flagged[0]['name']);
        $this->assertSame(-1.0, $flagged[0]['lossPerThousand']);
    }

    /** Selling at exactly cost is still work done for nothing. */
    public function test_a_service_priced_exactly_at_cost_is_flagged(): void
    {
        BotService::factory()->for($this->tenant)->create([
            'cost_price' => '2.0000',
            'my_price' => '2.0000',
        ]);

        $this->assertCount(1, $this->report()->underwater());
    }

    /** An unknown cost is not a loss — it is unknown. */
    public function test_a_service_without_a_cost_is_not_flagged(): void
    {
        BotService::factory()->for($this->tenant)->withoutCost()->create();

        $this->assertEmpty($this->report()->underwater());
    }

    /** A hidden service is not on sale, so its price is not costing anything. */
    public function test_only_services_actually_on_sale_are_flagged(): void
    {
        BotService::factory()->for($this->tenant)->hidden()->create([
            'cost_price' => '3.0000',
            'my_price' => '2.0000',
        ]);

        $this->assertEmpty($this->report()->underwater());
    }
}
