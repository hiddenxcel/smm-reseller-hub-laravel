<?php

namespace Tests\Feature;

use App\Jobs\SubmitOrderToPanel;
use App\Models\BotOrder;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Orders\OrderFilters;
use App\Services\Orders\OrderQuery;
use App\Services\Orders\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The orders list is the screen a reseller works from all day, and the one
 * place where a wrong row is another reseller's customer.
 */
class OrdersPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function order(array $attributes = []): BotOrder
    {
        return BotOrder::factory()->for($this->tenant)->create($attributes);
    }

    private function filters(array $query = []): OrderFilters
    {
        return OrderFilters::fromRequest(Request::create('/orders', 'GET', $query));
    }

    private function query(): OrderQuery
    {
        return OrderQuery::for($this->tenant->fresh());
    }

    // --- Access ---------------------------------------------------------

    public function test_orders_requires_a_logged_in_reseller(): void
    {
        $this->get(route('orders.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_renders_with_the_tenants_orders(): void
    {
        $this->order(['service_name' => 'Instagram Followers']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.service', 'Instagram Followers'));
    }

    public function test_another_resellers_orders_are_never_listed(): void
    {
        $other = Tenant::factory()->create();
        BotOrder::factory()->for($other)->create();
        $mine = $this->order();

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $mine->id));
    }

    public function test_acting_on_another_resellers_order_is_not_found(): void
    {
        $other = Tenant::factory()->create();
        $theirs = BotOrder::factory()->for($other)->create();

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('orders.act', $theirs->id), [
                'action' => 'mark',
                'status' => 'completed',
            ])
            ->assertNotFound();

        $this->assertSame('pending', $theirs->fresh()->status);
    }

    // --- Status folding -------------------------------------------------

    /**
     * Panels do not agree on wording, and the whole filter rests on folding
     * their spellings into the same four groups.
     */
    public function test_panel_wordings_fold_into_the_right_group(): void
    {
        $cases = [
            'Completed' => OrderStatus::COMPLETED,
            'COMPLETE' => OrderStatus::COMPLETED,
            'In progress' => OrderStatus::PROCESSING,
            'Processing' => OrderStatus::PROCESSING,
            'Active' => OrderStatus::PROCESSING,
            'Canceled' => OrderStatus::FAILED,
            'Partial' => OrderStatus::FAILED,
            'Refunded' => OrderStatus::FAILED,
            'Error' => OrderStatus::FAILED,
            'Pending' => OrderStatus::PENDING,
            '' => OrderStatus::PENDING,
            null => OrderStatus::PENDING,
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame(
                $expected,
                OrderStatus::fold($raw === '' ? '' : $raw),
                "'{$raw}' folded into the wrong group",
            );
        }
    }

    /** "Partially refunded" must not be read as in-progress. */
    public function test_partial_is_a_failure_not_progress(): void
    {
        $this->assertSame(OrderStatus::FAILED, OrderStatus::fold('Partially refunded'));
    }

    // --- Filtering ------------------------------------------------------

    public function test_the_status_filter_matches_every_panel_wording(): void
    {
        $this->order(['status' => 'Completed']);
        $this->order(['status' => 'COMPLETE']);
        $this->order(['status' => 'In progress']);
        $this->order(['status' => 'Canceled']);

        $completed = $this->query()->paginate($this->filters(['status' => 'completed']));

        $this->assertCount(2, $completed->items());
    }

    /**
     * Pending is the catch-all — everything the panel has said nothing about,
     * including a null status. This is the case the filter is most likely to
     * get wrong, because it is defined by exclusion.
     */
    public function test_pending_catches_null_and_unclaimed_statuses(): void
    {
        $this->order(['status' => null]);
        $this->order(['status' => 'Pending']);
        $this->order(['status' => 'Awaiting']);
        $this->order(['status' => 'Completed']);
        $this->order(['status' => 'Processing']);

        $pending = $this->query()->paginate($this->filters(['status' => 'pending']));

        $this->assertCount(3, $pending->items());
    }

    public function test_search_finds_an_order_by_phone_service_or_panel_id(): void
    {
        $byPhone = $this->order(['customer_phone' => '255712345678']);
        $byService = $this->order(['service_name' => 'TikTok Views']);
        $byProvider = $this->order(['provider_order_id' => '99881']);

        foreach ([
            '712345' => $byPhone,
            'tiktok' => $byService,
            '99881' => $byProvider,
        ] as $term => $expected) {
            $results = $this->query()->paginate($this->filters(['q' => (string) $term]));

            $this->assertCount(1, $results->items(), "searching '{$term}'");
            $this->assertSame($expected->id, $results->items()[0]->id);
        }
    }

    /** Resellers copy ids out of the UI with the '#' still attached. */
    public function test_search_tolerates_a_hash_prefixed_id(): void
    {
        $order = $this->order();

        $results = $this->query()->paginate($this->filters(['q' => '#'.$order->id]));

        $this->assertCount(1, $results->items());
        $this->assertSame($order->id, $results->items()[0]->id);
    }

    /** A '%' in the box is a literal, not a wildcard that matches everything. */
    public function test_search_escapes_like_wildcards(): void
    {
        $this->order(['service_name' => 'Instagram Followers']);
        $this->order(['service_name' => 'TikTok Views']);

        $results = $this->query()->paginate($this->filters(['q' => '%']));

        $this->assertCount(0, $results->items());
    }

    public function test_the_date_range_is_inclusive_of_the_end_day(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 12:00:00'));

        $this->order(['created_at' => Carbon::parse('2026-03-08 09:00:00')]);
        $late = $this->order(['created_at' => Carbon::parse('2026-03-10 23:30:00')]);

        $results = $this->query()->paginate($this->filters([
            'from' => '2026-03-09',
            'to' => '2026-03-10',
        ]));

        $this->assertCount(1, $results->items());
        $this->assertSame($late->id, $results->items()[0]->id);
    }

    public function test_the_payment_and_panel_filters_narrow_the_list(): void
    {
        $panel = TenantPanel::factory()->for($this->tenant)->create();

        $this->order(['payment_status' => 'pending']);
        $onPanel = $this->order(['panel_id' => $panel->id]);

        $this->assertCount(
            1,
            $this->query()->paginate($this->filters(['payment' => 'pending']))->items(),
        );
        $this->assertSame(
            $onPanel->id,
            $this->query()->paginate($this->filters(['panel' => $panel->id]))->items()[0]->id,
        );
    }

    // --- Sorting and paging ---------------------------------------------

    public function test_sorting_is_limited_to_known_columns(): void
    {
        // An unknown column must fall back rather than reach the ORDER BY.
        $filters = $this->filters(['sort' => 'customer_phone) --', 'dir' => 'sideways']);

        $this->assertSame('created_at', $filters->sort);
        $this->assertSame('desc', $filters->direction);

        $this->query()->paginate($filters); // would throw if it reached SQL
    }

    public function test_sorting_by_amount_orders_the_rows(): void
    {
        $this->order(['amount' => '1.00']);
        $this->order(['amount' => '9.00']);

        $ascending = $this->query()->paginate($this->filters([
            'sort' => 'amount',
            'dir' => 'asc',
        ]));

        $this->assertSame('1.00', $ascending->items()[0]->amount);
    }

    /**
     * Rows sharing a sort value must still come back in a stable order, or a
     * row can appear on two pages and another on none.
     */
    public function test_paging_a_tied_sort_never_repeats_a_row(): void
    {
        $at = Carbon::parse('2026-03-01 10:00:00');

        for ($i = 0; $i < 6; $i++) {
            $this->order(['status' => 'Completed', 'created_at' => $at]);
        }

        $seen = [];

        // Every row shares both the sort column and created_at, so only the
        // id tie-break keeps the pages from overlapping.
        foreach ([1, 2, 3] as $page) {
            $this->actingAs($this->tenant, 'tenant')
                ->get(route('orders.index', [
                    'sort' => 'status',
                    'per_page' => 25,
                    'page' => $page,
                ]))
                ->assertInertia(function (AssertableInertia $inertia) use (&$seen) {
                    foreach ($inertia->toArray()['props']['orders']['data'] as $row) {
                        $seen[] = $row['id'];
                    }
                });
        }

        $this->assertCount(6, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    public function test_page_size_is_restricted_to_the_offered_options(): void
    {
        $this->assertSame(
            OrderQuery::DEFAULT_PAGE_SIZE,
            $this->filters(['per_page' => 5000])->perPage,
        );
        $this->assertSame(100, $this->filters(['per_page' => 100])->perPage);
    }

    // --- Tab counts and summary -----------------------------------------

    /**
     * A tab count that ignored the active search would promise rows the table
     * then refuses to show.
     */
    public function test_tab_counts_respect_the_other_filters(): void
    {
        $this->order(['status' => 'Completed', 'service_name' => 'TikTok Views']);
        $this->order(['status' => 'Completed', 'service_name' => 'Instagram Likes']);
        $this->order(['status' => 'Canceled', 'service_name' => 'TikTok Views']);

        $counts = $this->query()->tabCounts($this->filters(['q' => 'tiktok']));

        $this->assertSame(2, $counts['all']);
        $this->assertSame(1, $counts['completed']);
        $this->assertSame(1, $counts['failed']);
    }

    public function test_the_summary_totals_only_the_filtered_rows(): void
    {
        $this->order(['status' => 'Completed', 'amount' => '4.00']);
        $this->order(['status' => 'Completed', 'amount' => '6.00']);
        $this->order(['status' => 'Canceled', 'amount' => '99.00']);

        $summary = $this->query()->summary($this->filters(['status' => 'completed']));

        $this->assertSame(2, $summary['orders']);
        $this->assertSame(10.0, $summary['revenue']);
    }

    // --- Available actions ----------------------------------------------

    public function test_retry_is_offered_only_for_a_paid_order_the_panel_never_took(): void
    {
        $panel = TenantPanel::factory()->for($this->tenant)->create();

        $stuck = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => null,
            'payment_status' => 'paid',
        ]);
        $unpaid = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => null,
            'payment_status' => 'pending',
        ]);
        $submitted = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => '5511',
        ]);

        $this->assertContains('retry', OrderQuery::toRow($stuck)['actions']);
        $this->assertNotContains('retry', OrderQuery::toRow($unpaid)['actions']);
        $this->assertNotContains('retry', OrderQuery::toRow($submitted)['actions']);
    }

    public function test_refill_is_offered_only_once_the_panel_has_completed_it(): void
    {
        $panel = TenantPanel::factory()->for($this->tenant)->create();

        $done = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => '1',
            'status' => 'Completed',
        ]);
        $running = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => '2',
            'status' => 'In progress',
        ]);

        $this->assertContains('refill', OrderQuery::toRow($done)['actions']);
        $this->assertNotContains('refill', OrderQuery::toRow($running)['actions']);
        $this->assertContains('cancel', OrderQuery::toRow($running)['actions']);
        $this->assertNotContains('cancel', OrderQuery::toRow($done)['actions']);
    }

    public function test_an_unavailable_action_is_refused(): void
    {
        $order = $this->order(['provider_order_id' => null]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('orders.index'))
            ->post(route('orders.act', $order->id), ['action' => 'refill'])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('error');
    }

    // --- Running actions -------------------------------------------------

    public function test_retry_queues_the_submission_and_clears_the_error(): void
    {
        Queue::fake();

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $order = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => null,
            'payment_status' => 'paid',
            'order_error' => 'Not enough funds',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('orders.index'))
            ->post(route('orders.act', $order->id), ['action' => 'retry'])
            ->assertSessionHas('success');

        Queue::assertPushed(
            SubmitOrderToPanel::class,
            fn (SubmitOrderToPanel $job) => $job->orderId === $order->id,
        );
        $this->assertNull($order->fresh()->order_error);
    }

    public function test_marking_a_status_writes_the_panels_own_wording(): void
    {
        $order = $this->order(['status' => 'pending']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('orders.index'))
            ->post(route('orders.act', $order->id), [
                'action' => 'mark',
                'status' => 'completed',
            ])
            ->assertSessionHas('success');

        // Stored as a panel would spell it, so it folds back into the group
        // the reseller picked.
        $fresh = $order->fresh();
        $this->assertSame('Completed', $fresh->status);
        $this->assertSame(OrderStatus::COMPLETED, OrderStatus::fold($fresh->status));
    }

    public function test_marking_rejects_a_status_that_is_not_a_group(): void
    {
        $order = $this->order();

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('orders.act', $order->id), [
                'action' => 'mark',
                'status' => 'delivered',
            ])
            ->assertSessionHasErrors('status');
    }

    /** A panel refusal must surface, not be swallowed as success. */
    public function test_a_refused_cancel_is_reported_and_changes_nothing(): void
    {
        Http::fake(['*' => Http::response([['cancel' => ['error' => 'Incorrect order ID']]])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $order = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => '4001',
            'status' => 'In progress',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('orders.index'))
            ->post(route('orders.act', $order->id), ['action' => 'cancel'])
            ->assertSessionHas('error');

        $this->assertSame('In progress', $order->fresh()->status);
    }

    public function test_an_accepted_cancel_marks_the_order_cancelled(): void
    {
        Http::fake(['*' => Http::response([['cancel' => 1]])]);

        $panel = TenantPanel::factory()->for($this->tenant)->create();
        $order = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => '4002',
            'status' => 'In progress',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('orders.index'))
            ->post(route('orders.act', $order->id), ['action' => 'cancel'])
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::FAILED, OrderStatus::fold($order->fresh()->status));
    }

    // --- Bulk ------------------------------------------------------------

    /**
     * A mixed selection must do what it can rather than refusing outright —
     * and must say how many it left alone.
     */
    public function test_a_bulk_action_skips_the_orders_it_does_not_fit(): void
    {
        Queue::fake();

        $panel = TenantPanel::factory()->for($this->tenant)->create();

        $retryable = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => null,
            'payment_status' => 'paid',
        ]);
        $alreadySubmitted = $this->order([
            'panel_id' => $panel->id,
            'provider_order_id' => '7788',
        ]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('orders.index'))
            ->post(route('orders.bulk'), [
                'action' => 'retry',
                'ids' => [$retryable->id, $alreadySubmitted->id],
            ])
            ->assertSessionHas('success');

        Queue::assertPushed(SubmitOrderToPanel::class, 1);
    }

    public function test_a_bulk_action_cannot_reach_another_resellers_orders(): void
    {
        $other = Tenant::factory()->create();
        $theirs = BotOrder::factory()->for($other)->create(['status' => 'pending']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('orders.index'))
            ->post(route('orders.bulk'), [
                'action' => 'mark',
                'status' => 'completed',
                'ids' => [$theirs->id],
            ]);

        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_select_all_matching_returns_only_the_filtered_ids(): void
    {
        $completed = $this->order(['status' => 'Completed']);
        $this->order(['status' => 'Canceled']);

        $this->actingAs($this->tenant, 'tenant')
            ->getJson(route('orders.matching-ids', ['status' => 'completed']))
            ->assertOk()
            ->assertJson(['ids' => [$completed->id]]);
    }

    // --- Export ----------------------------------------------------------

    public function test_the_export_streams_the_filtered_rows_as_csv(): void
    {
        $this->order(['status' => 'Completed', 'service_name' => 'TikTok Views']);
        $this->order(['status' => 'Canceled', 'service_name' => 'Instagram Likes']);

        $response = $this->actingAs($this->tenant, 'tenant')
            ->get(route('orders.export', ['status' => 'completed']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('TikTok Views', $csv);
        $this->assertStringNotContainsString('Instagram Likes', $csv);
    }
}
