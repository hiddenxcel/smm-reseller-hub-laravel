<?php

namespace Tests\Feature;

use App\Enums\ServiceKey;
use App\Models\BotPayment;
use App\Models\BotService;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Superadmin;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Services\Admin\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The read-only screens: tickets, the catalogue, and the numbers.
 *
 * Two lines matter here.
 *
 * **Tickets are read-only.** A ticket is a conversation between a reseller and
 * their customer, on the reseller's number and under their business name. There
 * is no reply route at all, so there is nothing to get wrong later.
 *
 * **Revenue means subscription_payments.** Their customers' payments live in
 * bot_payments and are the reseller's money. Counting those as ours would
 * overstate the business by an order of magnitude, and it is an easy mistake
 * because the two tables have the same shape.
 */
class AdminOversightTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->actingAs($this->admin, 'superadmin');
    }

    // ---- tickets ---------------------------------------------------------

    public function test_the_ticket_list_spans_every_reseller(): void
    {
        Ticket::factory()->count(2)->create();
        Ticket::factory()->create();

        $this->get('/hx-control/tickets')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Tickets/Index')
                ->has('tickets.data', 3),
        );
    }

    public function test_a_ticket_handed_to_a_person_is_its_own_state(): void
    {
        Ticket::factory()->create(['status' => 'open', 'handed_over_at' => null]);
        Ticket::factory()->create([
            'status' => 'open',
            'handed_over_at' => now()->subHours(2),
        ]);

        $this->get('/hx-control/tickets?state=handed_over')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.handedOver', true),
        );
    }

    public function test_a_handoff_nobody_answered_is_flagged_as_stalled(): void
    {
        Ticket::factory()->create([
            'status' => 'open',
            'handed_over_at' => now()->subDays(2),
        ]);

        $this->get('/hx-control/tickets')->assertInertia(
            fn (AssertableInertia $page) => $page->where('tickets.data.0.stalled', true),
        );
    }

    public function test_a_recent_handoff_is_not_flagged(): void
    {
        Ticket::factory()->create([
            'status' => 'open',
            'handed_over_at' => now()->subHour(),
        ]);

        $this->get('/hx-control/tickets')->assertInertia(
            fn (AssertableInertia $page) => $page->where('tickets.data.0.stalled', false),
        );
    }

    public function test_the_thread_can_be_read(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Order never arrived']);

        $ticket->messages()->create([
            'sender' => 'customer',
            'message' => 'Where is my order?',
        ]);

        $this->getJson("/hx-control/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('ticket.subject', 'Order never arrived')
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.message', 'Where is my order?');
    }

    public function test_there_is_no_way_to_reply_to_a_ticket_from_the_console(): void
    {
        $ticket = Ticket::factory()->create();

        // Not "returns 403" — the route does not exist, so no future change can
        // accidentally expose one.
        $this->post("/hx-control/tickets/{$ticket->id}/reply", ['message' => 'Hello'])
            ->assertNotFound();
    }

    // ---- catalogue -------------------------------------------------------

    public function test_the_catalogue_screen_counts_across_resellers(): void
    {
        BotService::factory()->count(3)->create(['status' => BotService::ACTIVE]);
        BotService::factory()->create([
            'status' => BotService::PAUSED,
            'auto_paused' => true,
        ]);

        $this->get('/hx-control/catalogue')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Catalogue/Index')
                ->where('kpis.services', 4)
                ->where('kpis.active', 3)
                ->where('kpis.autoPaused', 1),
        );
    }

    public function test_there_is_no_route_to_edit_a_resellers_service(): void
    {
        $service = BotService::factory()->create();

        // Editing from here would change what someone else's customers pay,
        // without that reseller knowing.
        $this->patch("/hx-control/catalogue/{$service->id}", ['my_price' => 999])
            ->assertNotFound();
    }

    // ---- reports ---------------------------------------------------------

    public function test_reports_count_platform_revenue_not_reseller_revenue(): void
    {
        $tenant = Tenant::factory()->create();

        SubscriptionPayment::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'gateway' => 'cryptomus',
            'transaction_ref' => 'REF-1',
            'amount' => 17.00,
            'currency' => 'USD',
            'months' => 1,
            'status' => 'success',
        ]);

        // Their customer's money. Must never appear as ours.
        BotPayment::factory()->create([
            'tenant_id' => $tenant->id,
            'amount' => 5000.00,
            'status' => 'success',
        ]);

        $growth = Reports::make()->growth();

        $this->assertSame(17.0, $growth['revenue']['value']);
    }

    public function test_mrr_comes_from_live_subscriptions_at_plan_prices(): void
    {
        $plan = Plan::factory()->create([
            'service_key' => ServiceKey::OrderBot,
            'price_monthly' => 17.00,
        ]);

        Subscription::factory()->count(3)->active()->create(['plan_id' => $plan->id]);

        // Cancelled and expired rows contribute nothing.
        Subscription::factory()->create([
            'plan_id' => $plan->id,
            'status' => 'cancelled',
        ]);
        Subscription::factory()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'ends_at' => now()->subDay(),
        ]);

        $mrr = Reports::make()->mrr();

        $this->assertSame(51.0, $mrr['total']);
    }

    public function test_a_live_subscription_with_no_plan_is_reported_not_guessed(): void
    {
        Subscription::factory()->active()->create(['plan_id' => null]);

        $mrr = Reports::make()->mrr();

        // Inventing a figure for it would hide a data problem.
        $this->assertSame(0.0, $mrr['total']);
        $this->assertSame(1, $mrr['unpriced']);
    }

    public function test_the_reports_page_loads(): void
    {
        $this->get('/hx-control/reports')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Reports/Index')
                ->has('mrr')
                ->has('growth'),
        );
    }

    public function test_conversion_counts_signups_who_have_paid(): void
    {
        Tenant::factory()->count(3)->create(['first_payment_done' => false]);
        Tenant::factory()->create(['first_payment_done' => true]);

        $rows = collect(Reports::make()->conversion());
        $thisMonth = $rows->firstWhere('month', now()->format('Y-m'));

        $this->assertSame(4, $thisMonth['signups']);
        $this->assertSame(1, $thisMonth['paid']);
        $this->assertSame(25.0, $thisMonth['rate']);
    }

    // ---- access ----------------------------------------------------------

    public function test_a_reseller_cannot_reach_any_of_these(): void
    {
        Auth::guard('superadmin')->logout();

        $this->actingAs(Tenant::factory()->create(), 'tenant');

        foreach (['tickets', 'catalogue', 'reports'] as $path) {
            $this->get("/hx-control/{$path}")->assertRedirect(route('admin.login'));
        }
    }
}
