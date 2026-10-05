<?php

namespace Tests\Feature;

use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Services\Dashboard\AnalyticsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The analytics page is the dashboard's longer view, so it has to agree with
 * the dashboard on what a number means — and, like it, every figure is an
 * aggregate, which is exactly where a missing tenant scope hides.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function report(int $days = 30, ?Tenant $tenant = null): AnalyticsReport
    {
        return AnalyticsReport::for(($tenant ?? $this->tenant)->fresh(), $days);
    }

    private function order(array $attributes = [], ?Tenant $tenant = null): BotOrder
    {
        return BotOrder::factory()->for($tenant ?? $this->tenant)->create([
            'customer_phone' => '255700000001',
            'service_name' => 'Instagram Followers',
            'amount' => '10.00',
            'payment_status' => 'paid',
            'status' => 'Completed',
            ...$attributes,
        ]);
    }

    // ---- the page --------------------------------------------------------

    public function test_the_page_needs_a_login(): void
    {
        $this->get(route('analytics'))->assertRedirect(route('login'));
    }

    public function test_a_new_tenant_sees_zeroes_not_an_error(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('analytics'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Analytics')
                ->where('range', 30)
                ->where('summary.revenue.value', 0)
                ->where('summary.orders.value', 0)
                // Nothing to divide by, so no "0% completed" that reads as failure.
                ->where('summary.completionRate', null)
                ->where('summary.averageOrder', null)
                ->where('summary.repeatRate', null)
                ->where('summary.profit', null)
                ->has('trend', 30)
            );
    }

    public function test_the_window_is_a_query_string_and_junk_falls_back(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->get(route('analytics', ['range' => 7]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('range', 7)->has('trend', 7));

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('analytics', ['range' => 'forever']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('range', 30));

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('analytics', ['range' => 365]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('range', 30));
    }

    // ---- the numbers -----------------------------------------------------

    public function test_revenue_counts_only_successful_payments_in_the_window(): void
    {
        BotPayment::factory()->for($this->tenant)->create(['amount' => '10.00', 'status' => 'success']);
        BotPayment::factory()->for($this->tenant)->create(['amount' => '99.00', 'status' => 'pending']);
        BotPayment::factory()->for($this->tenant)->create([
            'amount' => '500.00',
            'status' => 'success',
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $this->assertSame(10.0, $this->report()->summary()['revenue']['value']);
    }

    public function test_the_change_is_against_the_window_before_it(): void
    {
        BotPayment::factory()->for($this->tenant)->create(['amount' => '20.00', 'status' => 'success']);
        BotPayment::factory()->for($this->tenant)->create([
            'amount' => '10.00',
            'status' => 'success',
            'created_at' => Carbon::now()->subDays(40),
        ]);

        $this->assertSame(100.0, $this->report(30)->summary()['revenue']['delta']);
    }

    public function test_completion_average_and_repeat_rates(): void
    {
        $this->order(['customer_phone' => '255700000001', 'amount' => '10.00']);
        $this->order(['customer_phone' => '255700000001', 'amount' => '20.00']);
        $this->order(['customer_phone' => '255700000002', 'amount' => '30.00', 'status' => 'Canceled']);
        $this->order(['customer_phone' => '255700000003', 'amount' => '40.00', 'status' => 'In progress']);

        $summary = $this->report()->summary();

        $this->assertSame(4, $summary['orders']['value']);
        // Two of the four orders completed; one was cancelled, one is still running.
        $this->assertSame(50, $summary['completionRate']);
        $this->assertSame(25.0, $summary['averageOrder']);
        // One of three customers ordered more than once.
        $this->assertSame(33, $summary['repeatRate']);
    }

    public function test_unpaid_orders_are_failed_not_in_progress(): void
    {
        $this->order(['payment_status' => 'failed', 'status' => null]);
        $this->order(['status' => 'Processing']);

        $mix = $this->report()->statusMix();

        $this->assertSame(1, $mix['failed']);
        $this->assertSame(1, $mix['pending']);
        $this->assertSame(0, $mix['completed']);
    }

    public function test_profit_needs_a_recorded_cost(): void
    {
        $this->order(['amount' => '10.00', 'charge' => null]);

        $this->assertNull($this->report()->summary()['profit']);

        $this->order(['amount' => '10.00', 'charge' => '4.0000']);

        $this->assertSame(['value' => 6.0, 'margin' => 60], $this->report()->summary()['profit']);
    }

    public function test_top_services_are_ranked_by_revenue(): void
    {
        $this->order(['service_name' => 'Cheap', 'amount' => '1.00']);
        $this->order(['service_name' => 'Cheap', 'amount' => '1.00']);
        $this->order(['service_name' => 'Pricey', 'amount' => '50.00']);

        $top = $this->report()->topServices();

        $this->assertSame('Pricey', $top[0]['name']);
        $this->assertSame(2, $top[1]['orders']);
    }

    public function test_timing_counts_by_weekday_and_hour(): void
    {
        // A Monday at 09:30.
        $monday = Carbon::now()->startOfWeek()->subWeek()->setTime(9, 30);

        $this->order(['created_at' => $monday]);
        $this->order(['created_at' => $monday->copy()->setTime(9, 45)]);

        $timing = $this->report(30)->timing();

        $this->assertSame('Mon', $timing['weekdays'][0]['label']);
        $this->assertSame(2, $timing['weekdays'][0]['orders']);
        $this->assertSame(2, $timing['hours'][9]['orders']);
        $this->assertSame(0, $timing['hours'][10]['orders']);
        $this->assertCount(7, $timing['weekdays']);
        $this->assertCount(24, $timing['hours']);
    }

    public function test_gateways_sum_successful_payments_only(): void
    {
        BotPayment::factory()->for($this->tenant)->create(['gateway' => 'snippe', 'amount' => '10.00', 'status' => 'success']);
        BotPayment::factory()->for($this->tenant)->create(['gateway' => 'snippe', 'amount' => '5.00', 'status' => 'success']);
        BotPayment::factory()->for($this->tenant)->create(['gateway' => 'stripe', 'amount' => '80.00', 'status' => 'failed']);

        $gateways = $this->report()->gateways();

        $this->assertCount(1, $gateways);
        $this->assertSame(15.0, $gateways[0]['revenue']);
        $this->assertSame(2, $gateways[0]['payments']);
    }

    // ---- isolation -------------------------------------------------------

    public function test_another_tenants_data_never_appears(): void
    {
        $other = Tenant::factory()->create();

        BotPayment::factory()->for($other)->create(['amount' => '1000.00', 'status' => 'success', 'gateway' => 'other']);
        $this->order(['amount' => '999.00', 'service_name' => 'Theirs', 'customer_phone' => '255799999999'], $other);

        $report = $this->report();
        $summary = $report->summary();

        $this->assertSame(0.0, $summary['revenue']['value']);
        $this->assertSame(0, $summary['orders']['value']);
        $this->assertSame([], $report->topServices());
        $this->assertSame([], $report->gateways());
        $this->assertSame([], $report->topCustomers());
    }
}
