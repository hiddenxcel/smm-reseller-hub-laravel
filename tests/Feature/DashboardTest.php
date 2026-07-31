<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The dashboard reports on money, so a wrong number here is worse than a
 * missing one — a reseller makes decisions on it.
 *
 * Every figure is an aggregate, which is exactly where a missing tenant scope
 * hides: no row is ever displayed, but the totals are someone else's.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function metrics(): DashboardMetrics
    {
        return DashboardMetrics::for($this->tenant->fresh());
    }

    private function payment(array $attributes = []): BotPayment
    {
        return BotPayment::factory()
            ->for($this->tenant)
            ->create(['status' => 'success', ...$attributes]);
    }

    private function logMessage(
        string $direction,
        ?Carbon $at = null,
        string $botType = 'order',
    ): void {
        DB::table('bot_messages')->insert([
            'tenant_id' => $this->tenant->id,
            'customer_phone' => '255700000000',
            'direction' => $direction,
            'message' => 'hi',
            'bot_type' => $botType,
            'created_at' => ($at ?? Carbon::now())->toDateTimeString(),
        ]);
    }

    // ---- the page --------------------------------------------------------

    public function test_the_dashboard_needs_a_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_it_renders_with_everything_the_page_needs(): void
    {
        $response = $this->actingAs($this->tenant, 'tenant')->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('businessName', $this->tenant->business_name)
            ->has('kpis.revenue')
            ->has('kpis.orders')
            ->has('kpis.customers')
            ->has('botStatus')
            ->has('trend', DashboardMetrics::TREND_DAYS)
            ->has('statusMix')
            ->has('setup.steps', 5)
        );
    }

    public function test_a_brand_new_tenant_sees_zeroes_not_an_error(): void
    {
        $response = $this->actingAs($this->tenant, 'tenant')->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.revenue.value', 0)
            ->where('kpis.orders.value', 0)
            // Nothing to compare against yet, so no misleading "+100%".
            ->where('kpis.revenue.delta', null)
        );
    }

    // ---- revenue ---------------------------------------------------------

    public function test_revenue_counts_only_successful_payments(): void
    {
        $this->payment(['amount' => '10.00']);
        $this->payment(['amount' => '5.00']);
        BotPayment::factory()->for($this->tenant)->create([
            'amount' => '99.00',
            'status' => 'pending',
        ]);
        BotPayment::factory()->for($this->tenant)->create([
            'amount' => '50.00',
            'status' => 'failed',
        ]);

        $this->assertSame(15.0, $this->metrics()->kpis()['revenue']['value']);
    }

    public function test_revenue_ignores_another_tenants_payments(): void
    {
        $this->payment(['amount' => '10.00']);

        $other = Tenant::factory()->create();
        BotPayment::factory()->for($other)->create([
            'amount' => '1000.00',
            'status' => 'success',
        ]);

        $this->assertSame(10.0, $this->metrics()->kpis()['revenue']['value']);
    }

    public function test_the_delta_compares_against_the_previous_period(): void
    {
        // 10.00 in the current 30 days, 5.00 in the 30 before it.
        $this->payment(['amount' => '10.00']);
        $this->payment([
            'amount' => '5.00',
            'created_at' => Carbon::now()->subDays(40),
        ]);

        $kpis = $this->metrics()->kpis();

        $this->assertSame(10.0, $kpis['revenue']['value']);
        $this->assertSame(5.0, $kpis['revenue']['previous']);
        $this->assertSame(100.0, $kpis['revenue']['delta']);
    }

    // ---- the trend series ------------------------------------------------

    public function test_the_trend_covers_every_day_including_empty_ones(): void
    {
        $this->payment(['amount' => '7.00']);

        $trend = $this->metrics()->trend();

        $this->assertCount(DashboardMetrics::TREND_DAYS, $trend);
        $this->assertSame(Carbon::today()->toDateString(), end($trend)['date']);

        // A quiet day is a zero, not a gap — skipping it would compress time
        // and make a slow week look busy.
        $this->assertSame(0.0, $trend[0]['revenue']);
        $this->assertSame(7.0, end($trend)['revenue']);
    }

    public function test_the_trend_is_scoped_to_the_tenant(): void
    {
        $other = Tenant::factory()->create();
        BotPayment::factory()->for($other)->create([
            'amount' => '500.00',
            'status' => 'success',
        ]);

        $trend = $this->metrics()->trend();

        $this->assertSame(0.0, array_sum(array_column($trend, 'revenue')));
    }

    // ---- order outcomes --------------------------------------------------

    public function test_panel_statuses_fold_into_three_states(): void
    {
        foreach (['Completed', 'completed'] as $status) {
            BotOrder::factory()->for($this->tenant)->create(['status' => $status]);
        }

        foreach (['In progress', 'Processing', 'Pending'] as $status) {
            BotOrder::factory()->for($this->tenant)->create(['status' => $status]);
        }

        foreach (['Canceled', 'Partial refund'] as $status) {
            BotOrder::factory()->for($this->tenant)->create(['status' => $status]);
        }

        $mix = $this->metrics()->orderStatusMix();

        $this->assertSame(2, $mix['completed']);
        $this->assertSame(3, $mix['pending']);
        $this->assertSame(2, $mix['failed']);
    }

    public function test_an_unpaid_order_counts_as_failed_not_pending(): void
    {
        // The customer never completed the purchase, so showing it as "in
        // progress" would have a reseller waiting on nothing.
        BotOrder::factory()->for($this->tenant)->create([
            'payment_status' => 'failed',
            'status' => 'Pending',
        ]);

        $this->assertSame(1, $this->metrics()->orderStatusMix()['failed']);
    }

    // ---- bot status ------------------------------------------------------

    public function test_a_bot_with_no_number_reads_as_not_connected(): void
    {
        $status = $this->metrics()->botStatus();

        $this->assertSame('not_connected', $status['order']['state']);
        $this->assertSame('not_connected', $status['support']['state']);
    }

    public function test_a_connected_number_that_never_replied_says_so(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order']);

        $this->assertSame('never_replied', $this->metrics()->botStatus()['order']['state']);
    }

    public function test_a_recent_reply_reads_as_online(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order']);
        $this->logMessage('out');

        $this->assertSame('online', $this->metrics()->botStatus()['order']['state']);
    }

    public function test_a_bot_silent_for_a_day_reads_as_quiet(): void
    {
        // "Connected" is not the same as "working" — a stale token looks fine
        // on a settings page, so the dashboard leans on recent activity.
        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order']);
        $this->logMessage('out', Carbon::now()->subDays(3));

        $this->assertSame('idle', $this->metrics()->botStatus()['order']['state']);
    }

    // ---- the two bots are reported separately ----------------------------

    public function test_a_healthy_order_bot_does_not_vouch_for_support(): void
    {
        // The reseller sells these separately, on separate numbers and
        // separate subscriptions. One combined light would call the support
        // bot healthy on the order bot's evidence.
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'phone_number_id' => 'order-number',
            'bot_type' => 'order',
        ]);
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'phone_number_id' => 'support-number',
            'bot_type' => 'support',
        ]);

        $this->logMessage('out', botType: 'order');

        $status = $this->metrics()->botStatus();

        $this->assertSame('online', $status['order']['state']);
        $this->assertSame('never_replied', $status['support']['state']);
    }

    public function test_a_number_counts_only_for_the_bot_it_runs(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order']);

        $status = $this->metrics()->botStatus();

        $this->assertSame(1, $status['order']['numbersConnected']);
        $this->assertSame(0, $status['support']['numbersConnected']);
    }

    public function test_todays_message_count_is_per_bot(): void
    {
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'phone_number_id' => 'order-number',
            'bot_type' => 'order',
        ]);
        TenantWhatsApp::factory()->for($this->tenant)->create([
            'phone_number_id' => 'support-number',
            'bot_type' => 'support',
        ]);

        $this->logMessage('in', botType: 'order');
        $this->logMessage('out', botType: 'order');
        $this->logMessage('in', botType: 'support');

        $status = $this->metrics()->botStatus();

        $this->assertSame(2, $status['order']['messagesToday']);
        $this->assertSame(1, $status['support']['messagesToday']);
        $this->assertSame(3, $status['messagesToday']);
    }

    // ---- wallets, services, tickets --------------------------------------

    public function test_wallets_held_totals_customer_balances(): void
    {
        BotCustomer::factory()->for($this->tenant)->create(['balance' => '12.50']);
        BotCustomer::factory()->for($this->tenant)->create(['balance' => '7.50']);

        $other = Tenant::factory()->create();
        BotCustomer::factory()->for($other)->create(['balance' => '999.00']);

        $this->assertSame(20.0, $this->metrics()->kpis()['walletsHeld']);
    }

    public function test_top_services_rank_by_revenue(): void
    {
        BotOrder::factory()->for($this->tenant)->create([
            'service_name' => 'TikTok Likes',
            'amount' => '3.00',
        ]);
        BotOrder::factory()->for($this->tenant)->count(2)->create([
            'service_name' => 'Instagram Followers',
            'amount' => '10.00',
        ]);

        $top = $this->metrics()->topServices();

        $this->assertSame('Instagram Followers', $top[0]['name']);
        $this->assertSame(20.0, $top[0]['revenue']);
        $this->assertSame(2, $top[0]['orders']);
    }

    public function test_open_tickets_exclude_resolved_ones(): void
    {
        Ticket::factory()->for($this->tenant)->create(['status' => 'open']);
        Ticket::factory()->for($this->tenant)->create(['status' => 'pending']);
        Ticket::factory()->for($this->tenant)->create(['status' => 'resolved']);
        Ticket::factory()->for($this->tenant)->create(['status' => 'closed']);

        $this->assertSame(2, $this->metrics()->openTicketCount());
    }

    public function test_recent_orders_are_newest_first_and_tenant_scoped(): void
    {
        $other = Tenant::factory()->create();
        BotOrder::factory()->for($other)->create(['service_name' => 'Not yours']);

        BotOrder::factory()->for($this->tenant)->create(['service_name' => 'Older']);
        BotOrder::factory()->for($this->tenant)->create(['service_name' => 'Newer']);

        $recent = $this->metrics()->recentOrders();

        $this->assertCount(2, $recent);
        $this->assertSame('Newer', $recent[0]['service']);
        $this->assertNotContains('Not yours', array_column($recent, 'service'));
    }

    public function test_panels_report_their_last_known_balance(): void
    {
        TenantPanel::factory()->for($this->tenant)->create([
            'name' => 'Main Panel',
            'last_balance' => '42.75',
            'balance_currency' => 'USD',
        ]);

        $panels = $this->metrics()->panels();

        $this->assertSame('Main Panel', $panels[0]['name']);
        $this->assertSame(42.75, $panels[0]['balance']);
    }
}
