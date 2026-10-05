<?php

namespace Tests\Feature;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Services\Team\TeamAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Payments page is what a reseller's customers paid them, so the totals
 * have to be exactly the money that arrived — and, being aggregates, they are
 * where a missing tenant scope would hide.
 */
class PaymentsPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function payment(array $attributes = [], ?Tenant $tenant = null, ?BotCustomer $customer = null): BotPayment
    {
        $tenant ??= $this->tenant;
        $customer ??= BotCustomer::factory()->for($tenant)->create();

        return BotPayment::factory()->for($tenant)->create([
            'customer_id' => $customer->id,
            'status' => 'success',
            'amount' => '10.00',
            ...$attributes,
        ]);
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->tenant, 'tenant')->get(route('order-bot.payments', $query));
    }

    // ---- the page --------------------------------------------------------

    public function test_the_page_needs_a_login(): void
    {
        $this->get(route('order-bot.payments'))->assertRedirect(route('login'));
    }

    public function test_a_new_tenant_sees_an_empty_page_not_an_error(): void
    {
        $this->page()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrderBot/Payments')
            ->has('payments', 0)
            ->where('summary.received', 0)
            ->where('summary.paid', 0)
            ->where('tabCounts.all', 0)
            ->where('currency', 'USD')
            ->where('isFiltered', false));
    }

    public function test_the_summary_counts_money_that_arrived_and_what_is_waiting(): void
    {
        $this->payment(['amount' => '10.00', 'status' => 'success']);
        $this->payment(['amount' => '15.50', 'status' => 'success']);
        $this->payment(['amount' => '7.00', 'status' => 'pending']);
        $this->payment(['amount' => '99.00', 'status' => 'failed']);

        $this->page()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.received', 25.5)
            ->where('summary.paid', 2)
            ->where('summary.pending.amount', 7)
            ->where('summary.pending.count', 1)
            ->where('summary.failed', 1)
            ->where('tabCounts.all', 4)
            ->where('tabCounts.success', 2));
    }

    public function test_the_window_excludes_older_payments_until_all_time_is_chosen(): void
    {
        $this->payment(['amount' => '10.00']);
        $this->payment(['amount' => '500.00', 'created_at' => Carbon::now()->subDays(60)]);

        $this->page()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.received', 10)
            ->has('payments', 1));

        $this->page(['range' => 0])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.received', 510)
            ->has('payments', 2));

        $this->page(['range' => 7])->assertInertia(fn (AssertableInertia $page) => $page->where('filters.range', 7));
    }

    // ---- filters ---------------------------------------------------------

    public function test_the_status_gateway_and_type_filters(): void
    {
        $this->payment(['status' => 'success', 'gateway' => 'snippe', 'type' => 'wallet_topup']);
        $this->payment(['status' => 'pending', 'gateway' => 'snippe', 'type' => 'wallet_topup']);
        $this->payment(['status' => 'success', 'gateway' => 'stripe', 'type' => 'order_payment']);

        $this->page(['status' => 'pending'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 1)
            // The tabs keep their counts when one is chosen, or there would be
            // nothing left to click.
            ->where('tabCounts.all', 3));

        $this->page(['gateway' => 'stripe'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 1)
            ->where('payments.0.gateway', 'stripe')
            ->where('tabCounts.all', 1));

        $this->page(['type' => 'order_payment'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 1)
            ->where('payments.0.type', 'order_payment'));
    }

    public function test_junk_filters_fall_back_to_no_filter(): void
    {
        $this->payment();

        $this->page(['status' => 'bogus', 'type' => 'nope', 'range' => 9999])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments', 1)
                ->where('filters.status', null)
                ->where('filters.type', null)
                ->where('filters.range', 30));
    }

    public function test_only_gateways_actually_used_are_offered(): void
    {
        $this->payment(['gateway' => 'snippe']);
        $this->payment(['gateway' => 'snippe']);

        $this->page()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('gateways', 1)
            ->where('gateways.0.code', 'snippe'));
    }

    // ---- search ----------------------------------------------------------

    public function test_search_finds_by_reference_name_and_phone(): void
    {
        $amina = BotCustomer::factory()->for($this->tenant)->create(['name' => 'Amina Juma', 'phone' => '255712345678']);
        $other = BotCustomer::factory()->for($this->tenant)->create(['name' => 'Baraka', 'phone' => '255799000111']);

        $this->payment(['transaction_ref' => 'SMMTOP11111'], customer: $amina);
        $this->payment(['transaction_ref' => 'SMMTOP22222'], customer: $other);

        $this->page(['q' => 'smmtop11'])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 1));
        $this->page(['q' => 'amina'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 1)
            ->where('payments.0.customer.phone', '255712345678'));
        $this->page(['q' => '712345'])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 1));
        $this->page(['q' => 'nobody'])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 0));
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->payment(['transaction_ref' => 'SMMTOP11111']);
        $this->payment(['transaction_ref' => 'SMMTOP22222']);

        // Unescaped, "%" would match every reference.
        $this->page(['q' => '%'])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 0));
        $this->page(['q' => '_'])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 0));
    }

    // ---- paging and isolation -------------------------------------------

    public function test_the_list_is_paged(): void
    {
        $customer = BotCustomer::factory()->for($this->tenant)->create();

        for ($i = 0; $i < 30; $i++) {
            $this->payment(customer: $customer);
        }

        $this->page()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 25)
            ->where('meta.total', 30)
            ->where('meta.lastPage', 2));

        $this->page(['page' => 2])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 5));
    }

    public function test_another_tenants_payments_never_appear(): void
    {
        $other = Tenant::factory()->create();
        $this->payment(['amount' => '1000.00', 'gateway' => 'theirs', 'transaction_ref' => 'THEIRS001'], $other);
        $this->payment(['amount' => '5.00']);

        $this->page()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 1)
            ->where('summary.received', 5)
            ->where('tabCounts.all', 1)
            ->has('gateways', 1));

        $this->page(['q' => 'THEIRS001'])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 0));
        $this->page(['gateway' => 'theirs'])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 0));
    }

    public function test_customer_search_does_not_reach_another_tenants_customers(): void
    {
        $other = Tenant::factory()->create();
        $theirCustomer = BotCustomer::factory()->for($other)->create(['name' => 'Zuberi Secret', 'phone' => '255700999888']);

        // Mine, but with a customer that has a different name.
        $this->payment();
        // Theirs.
        $this->payment(customer: $theirCustomer, tenant: $other);

        $this->page(['q' => 'zuberi'])->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 0));
    }

    // ---- team ------------------------------------------------------------

    public function test_money_in_is_for_the_owner_and_admins_not_support_or_viewers(): void
    {
        $this->assertTrue(TeamAccess::allows('admin', 'order-bot.payments', 'GET'));
        $this->assertFalse(TeamAccess::allows('support', 'order-bot.payments', 'GET'));
        $this->assertFalse(TeamAccess::allows('viewer', 'order-bot.payments', 'GET'));
    }
}
