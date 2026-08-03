<?php

namespace Tests\Feature;

use App\Jobs\SendCustomerBroadcast;
use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\BotOrder;
use App\Models\BotPayment;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use App\Services\Customers\CustomerActions;
use App\Services\Customers\CustomerFilters;
use App\Services\Customers\CustomerQuery;
use App\Services\Customers\CustomerSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The customers screen. Two things here are worth more care than the rest: the
 * wallet, because it is money and it is edited by hand; and tenant isolation,
 * because a list page leaks other people's customers row by row.
 */
class CustomersPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function customer(array $attributes = []): BotCustomer
    {
        return BotCustomer::factory()->for($this->tenant)->create($attributes);
    }

    private function filters(array $query = []): CustomerFilters
    {
        return CustomerFilters::fromRequest(Request::create('/customers', 'GET', $query));
    }

    private function query(): CustomerQuery
    {
        return CustomerQuery::for($this->tenant->fresh());
    }

    private function logMessage(string $phone, string $direction, string $bot = 'order', ?Carbon $at = null): void
    {
        BotMessage::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'customer_phone' => $phone,
            'direction' => $direction,
            'message' => 'hello',
            'bot_type' => $bot,
            'created_at' => $at ?? now(),
        ]);
    }

    // --- Access ----------------------------------------------------------

    public function test_customers_requires_a_logged_in_reseller(): void
    {
        $this->get(route('customers.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_the_tenants_customers(): void
    {
        $this->customer(['name' => 'Asha Mwinyi']);

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('customers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Asha Mwinyi'));
    }

    public function test_another_resellers_customers_are_never_listed(): void
    {
        $other = Tenant::factory()->create();
        BotCustomer::factory()->for($other)->create();
        $mine = $this->customer();

        $this->actingAs($this->tenant, 'tenant')
            ->get(route('customers.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $mine->id));
    }

    public function test_opening_another_resellers_customer_is_not_found(): void
    {
        $theirs = BotCustomer::factory()->for(Tenant::factory()->create())->create();

        $this->actingAs($this->tenant, 'tenant')
            ->getJson(route('customers.show', [$theirs->id, 'overview']))
            ->assertNotFound();
    }

    public function test_adjusting_another_resellers_wallet_is_not_found(): void
    {
        $theirs = BotCustomer::factory()
            ->for(Tenant::factory()->create())
            ->create(['balance' => '10.00']);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('customers.act', $theirs->id), [
                'action' => 'wallet',
                'amount' => '50',
            ])
            ->assertNotFound();

        $this->assertSame('10.00', $theirs->fresh()->balance);
    }

    // --- Segments --------------------------------------------------------

    public function test_a_big_spender_is_vip(): void
    {
        $customer = $this->customer(['total_spent' => '150.00']);

        $this->assertContains(CustomerSegment::VIP, CustomerSegment::for($customer, 2));
    }

    /** The steady buyer who spends little each time is a VIP too. */
    public function test_many_orders_also_makes_a_vip(): void
    {
        $customer = $this->customer(['total_spent' => '5.00']);

        $this->assertContains(CustomerSegment::VIP, CustomerSegment::for($customer, 25));
    }

    /** Blocked overrides everything — a blocked customer is not shown as VIP. */
    public function test_blocked_overrides_every_other_segment(): void
    {
        $customer = $this->customer([
            'total_spent' => '500.00',
            'blocked_at' => now(),
        ]);

        $this->assertSame([CustomerSegment::BLOCKED], CustomerSegment::for($customer, 50));
    }

    public function test_the_segment_filter_matches_the_displayed_segment(): void
    {
        $vip = $this->customer(['total_spent' => '200.00']);
        $this->customer(['total_spent' => '1.00']);
        $blocked = $this->customer(['total_spent' => '900.00', 'blocked_at' => now()]);

        $vips = $this->query()->paginate($this->filters(['segment' => 'vip']));
        $blockedRows = $this->query()->paginate($this->filters(['segment' => 'blocked']));

        $this->assertCount(1, $vips->items());
        $this->assertSame($vip->id, $vips->items()[0]->id);
        $this->assertSame($blocked->id, $blockedRows->items()[0]->id);
    }

    // --- Search and filters ----------------------------------------------

    public function test_search_finds_a_customer_by_name_phone_email_or_code(): void
    {
        $byName = $this->customer(['name' => 'Juma Kondo']);
        $byPhone = $this->customer(['phone' => '255712345678']);
        $byEmail = $this->customer(['email' => 'asha@example.com']);
        $byCode = $this->customer(['referral_code' => 'CREF7777']);

        foreach ([
            'kondo' => $byName,
            '712345' => $byPhone,
            'asha@' => $byEmail,
            'cref7777' => $byCode,
        ] as $term => $expected) {
            $results = $this->query()->paginate($this->filters(['q' => (string) $term]));

            $this->assertCount(1, $results->items(), "searching '{$term}'");
            $this->assertSame($expected->id, $results->items()[0]->id);
        }
    }

    /** People write numbers down however they like; the search should not care. */
    public function test_a_phone_search_ignores_formatting(): void
    {
        $customer = $this->customer(['phone' => '255712345678']);

        foreach (['+255 712 345 678', '255-712-345-678'] as $term) {
            $results = $this->query()->paginate($this->filters(['q' => $term]));

            $this->assertCount(1, $results->items(), "searching '{$term}'");
            $this->assertSame($customer->id, $results->items()[0]->id);
        }
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $this->customer(['name' => 'Juma']);
        $this->customer(['name' => 'Asha']);

        $this->assertCount(0, $this->query()->paginate($this->filters(['q' => '%']))->items());
    }

    /** Which bot someone used is read from the message log, not a column. */
    public function test_the_bot_filter_reads_the_message_log(): void
    {
        $orderOnly = $this->customer(['phone' => '255700000001']);
        $supportOnly = $this->customer(['phone' => '255700000002']);
        $both = $this->customer(['phone' => '255700000003']);

        $this->logMessage($orderOnly->phone, 'in', 'order');
        $this->logMessage($supportOnly->phone, 'in', 'support');
        $this->logMessage($both->phone, 'in', 'order');
        $this->logMessage($both->phone, 'in', 'support');

        $this->assertCount(2, $this->query()->paginate($this->filters(['bot' => 'order']))->items());
        $this->assertCount(2, $this->query()->paginate($this->filters(['bot' => 'support']))->items());

        $bothRows = $this->query()->paginate($this->filters(['bot' => 'both']));
        $this->assertCount(1, $bothRows->items());
        $this->assertSame($both->id, $bothRows->items()[0]->id);
    }

    public function test_the_wallet_band_filter_narrows_the_list(): void
    {
        $this->customer(['balance' => '0.00']);
        $this->customer(['balance' => '2.50']);
        $this->customer(['balance' => '20.00']);
        $this->customer(['balance' => '500.00']);

        foreach (['empty' => 1, 'low' => 1, 'funded' => 1, 'high' => 1] as $band => $expected) {
            $this->assertCount(
                $expected,
                $this->query()->paginate($this->filters(['wallet' => $band]))->items(),
                "band '{$band}'",
            );
        }
    }

    public function test_sorting_is_limited_to_known_columns(): void
    {
        $filters = $this->filters(['sort' => 'balance) --', 'dir' => 'sideways']);

        $this->assertSame('last_seen_at', $filters->sort);
        $this->assertSame('desc', $filters->direction);

        $this->query()->paginate($filters); // would throw if it reached SQL
    }

    /**
     * A customer who has never messaged has no last_seen_at. Nulls at the top
     * of a recency sort would bury every row the reseller wanted.
     */
    public function test_customers_who_never_messaged_sort_last(): void
    {
        $this->customer(['last_seen_at' => null, 'name' => 'Never']);
        $this->customer(['last_seen_at' => now()->subDay(), 'name' => 'Recent']);

        $rows = $this->query()->paginate($this->filters(['sort' => 'last_seen_at']));

        $this->assertSame('Recent', $rows->items()[0]->name);
        $this->assertSame('Never', $rows->items()[1]->name);
    }

    public function test_tab_counts_respect_the_other_filters(): void
    {
        $this->customer(['name' => 'Juma Big', 'total_spent' => '500.00']);
        $this->customer(['name' => 'Juma Small', 'total_spent' => '1.00']);
        $this->customer(['name' => 'Asha Big', 'total_spent' => '500.00']);

        $counts = $this->query()->tabCounts($this->filters(['q' => 'juma']));

        $this->assertSame(2, $counts['all']);
        $this->assertSame(1, $counts['vip']);
    }

    // --- KPIs -------------------------------------------------------------

    public function test_the_kpis_report_the_whole_book(): void
    {
        $this->customer(['balance' => '10.00', 'total_spent' => '40.00']);
        $this->customer(['balance' => '5.00', 'total_spent' => '200.00']);

        $kpis = $this->query()->kpis();

        $this->assertSame(2, $kpis['total']['value']);
        $this->assertSame(15.0, $kpis['wallets']['value']);
        $this->assertSame(240.0, $kpis['lifetime']['value']);
        $this->assertSame(1, $kpis['vip']['value']);
    }

    // --- Wallet -----------------------------------------------------------

    public function test_adding_to_a_wallet_records_what_happened(): void
    {
        $customer = $this->customer(['balance' => '10.00']);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.act', $customer->id), [
                'action' => 'wallet',
                'amount' => '25.50',
                'reason' => 'Paid by M-Pesa',
            ])
            ->assertSessionHas('success');

        $this->assertSame('35.50', $customer->fresh()->balance);

        // The audit row is the whole point — the old platform wrote nothing.
        $this->assertDatabaseHas('bot_payments', [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'gateway' => 'manual',
            'amount' => '25.50',
            'status' => 'success',
        ]);
    }

    public function test_deducting_records_a_negative_movement(): void
    {
        $customer = $this->customer(['balance' => '40.00']);

        CustomerActions::adjustWallet($customer, '-15.00');

        $this->assertSame('25.00', $customer->fresh()->balance);
        $this->assertDatabaseHas('bot_payments', [
            'customer_id' => $customer->id,
            'gateway' => 'manual',
            'amount' => '-15.00',
        ]);
    }

    /** A wallet must never go negative, and a refused deduction records nothing. */
    public function test_a_deduction_beyond_the_balance_is_refused_entirely(): void
    {
        $customer = $this->customer(['balance' => '5.00']);

        $outcome = CustomerActions::adjustWallet($customer, '-50.00');

        $this->assertTrue($outcome->failed);
        $this->assertSame('5.00', $customer->fresh()->balance);
        $this->assertDatabaseMissing('bot_payments', ['customer_id' => $customer->id]);
    }

    public function test_a_zero_adjustment_is_refused(): void
    {
        $customer = $this->customer(['balance' => '5.00']);

        $this->assertTrue(CustomerActions::adjustWallet($customer, '0.00')->failed);
        $this->assertDatabaseMissing('bot_payments', ['customer_id' => $customer->id]);
    }

    public function test_a_nonsense_amount_never_reaches_the_wallet(): void
    {
        $customer = $this->customer(['balance' => '5.00']);

        $this->assertTrue(CustomerActions::adjustWallet($customer, '1); drop table')->failed);
        $this->assertSame('5.00', $customer->fresh()->balance);
    }

    // --- Block, edit, delete ----------------------------------------------

    public function test_blocking_and_unblocking(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.act', $customer->id), ['action' => 'block'])
            ->assertSessionHas('success');

        $this->assertNotNull($customer->fresh()->blocked_at);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.act', $customer->id), ['action' => 'unblock']);

        $this->assertNull($customer->fresh()->blocked_at);
    }

    /** Blocking keeps everything; it is a decision, not a deletion. */
    public function test_blocking_keeps_their_orders_and_balance(): void
    {
        $customer = $this->customer(['balance' => '30.00']);
        BotOrder::factory()->for($this->tenant)->create(['customer_id' => $customer->id]);

        CustomerActions::block($customer);

        $this->assertSame('30.00', $customer->fresh()->balance);
        $this->assertSame(1, $customer->orders()->count());
    }

    public function test_editing_normalises_tags(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->patch(route('customers.update', $customer->id), [
                'name' => 'Asha',
                'country' => 'tz',
                'tags' => ['  Wholesale ', 'wholesale', 'VIP', ''],
            ])
            ->assertSessionHas('success');

        $fresh = $customer->fresh();

        $this->assertSame('Asha', $fresh->name);
        $this->assertSame('TZ', $fresh->country);
        // Duplicates collapse case-insensitively; blanks are dropped.
        $this->assertSame(['Wholesale', 'VIP'], $fresh->tags);
    }

    public function test_a_customer_with_orders_cannot_be_deleted(): void
    {
        $customer = $this->customer();
        BotOrder::factory()->for($this->tenant)->create(['customer_id' => $customer->id]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->delete(route('customers.destroy', $customer->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('bot_customers', ['id' => $customer->id]);
    }

    public function test_a_customer_without_orders_can_be_deleted(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->delete(route('customers.destroy', $customer->id))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bot_customers', ['id' => $customer->id]);
    }

    public function test_adding_a_customer_by_hand_gives_them_a_referral_code(): void
    {
        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.store'), [
                'phone' => '+255 712 000 111',
                'name' => 'Walk-in',
            ])
            ->assertSessionHas('success');

        $customer = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->first();

        $this->assertSame('255712000111', $customer->phone);
        $this->assertNotNull($customer->referral_code);
    }

    public function test_the_same_phone_cannot_be_added_twice(): void
    {
        $this->customer(['phone' => '255712000111']);

        $this->actingAs($this->tenant, 'tenant')
            ->post(route('customers.store'), ['phone' => '255712000111'])
            ->assertSessionHasErrors('phone');
    }

    // --- Slide-over tabs ---------------------------------------------------

    public function test_each_tab_returns_only_its_own_data(): void
    {
        $customer = $this->customer();
        BotOrder::factory()->for($this->tenant)->create(['customer_id' => $customer->id]);
        BotPayment::factory()->for($this->tenant)->create(['customer_id' => $customer->id]);
        Ticket::factory()->for($this->tenant)->create([
            'customer_identifier' => $customer->phone,
        ]);
        $this->logMessage($customer->phone, 'in');

        foreach (['overview', 'orders', 'messages', 'wallet', 'tickets', 'activity'] as $tab) {
            $this->actingAs($this->tenant, 'tenant')
                ->getJson(route('customers.show', [$customer->id, $tab]))
                ->assertOk()
                ->assertJsonStructure([$tab === 'messages' ? 'messages' : $tab]);
        }
    }

    public function test_the_activity_timeline_merges_what_happened(): void
    {
        $customer = $this->customer();
        BotOrder::factory()->for($this->tenant)->create(['customer_id' => $customer->id]);
        BotPayment::factory()->for($this->tenant)->create([
            'customer_id' => $customer->id,
            'status' => 'success',
        ]);

        $activity = $this->actingAs($this->tenant, 'tenant')
            ->getJson(route('customers.show', [$customer->id, 'activity']))
            ->json('activity');

        $types = array_column($activity, 'type');

        $this->assertContains('order', $types);
        $this->assertContains('payment', $types);
        $this->assertContains('joined', $types);
    }

    // --- Messaging ---------------------------------------------------------

    /**
     * Meta refuses a free-form message outside 24 hours of the customer's last
     * inbound one. Refusing here, with a reason, beats letting the API fail.
     */
    public function test_a_message_outside_the_24_hour_window_is_refused(): void
    {
        Http::fake();

        $customer = $this->customer();
        $this->logMessage($customer->phone, 'in', 'order', now()->subDays(3));

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.act', $customer->id), [
                'action' => 'message',
                'text' => 'Hello again',
            ])
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    /** The bot talking does not reopen the window — only the customer can. */
    public function test_an_outbound_message_does_not_reopen_the_window(): void
    {
        Http::fake();

        $customer = $this->customer();
        $this->logMessage($customer->phone, 'in', 'order', now()->subDays(3));
        $this->logMessage($customer->phone, 'out', 'order', now()->subMinutes(5));

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.act', $customer->id), [
                'action' => 'message',
                'text' => 'Hello again',
            ])
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_a_message_inside_the_window_is_sent(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order']);

        $customer = $this->customer();
        $this->logMessage($customer->phone, 'in', 'order', now()->subHour());

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.act', $customer->id), [
                'action' => 'message',
                'text' => 'Your order is on the way',
            ])
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains(
            json_encode($request->data()),
            'Your order is on the way',
        ));
    }

    public function test_a_blocked_customer_is_not_messaged(): void
    {
        Http::fake();

        $customer = $this->customer(['blocked_at' => now()]);
        $this->logMessage($customer->phone, 'in', 'order', now()->subHour());

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.act', $customer->id), [
                'action' => 'message',
                'text' => 'Hello',
            ])
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    // --- Bulk ---------------------------------------------------------------

    public function test_a_bulk_block_skips_the_already_blocked(): void
    {
        $fresh = $this->customer();
        $already = $this->customer(['blocked_at' => now()]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.bulk'), [
                'action' => 'block',
                'ids' => [$fresh->id, $already->id],
            ])
            ->assertSessionHas('success');

        $this->assertNotNull($fresh->fresh()->blocked_at);
    }

    public function test_bulk_tagging_does_not_duplicate_an_existing_tag(): void
    {
        $customer = $this->customer(['tags' => ['vip']]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.bulk'), [
                'action' => 'tag',
                'ids' => [$customer->id],
                'tag' => 'VIP',
            ]);

        // Matched case-insensitively, so "VIP" does not join "vip".
        $this->assertSame(['vip'], $customer->fresh()->tags);
    }

    public function test_a_bulk_action_cannot_reach_another_resellers_customers(): void
    {
        $theirs = BotCustomer::factory()->for(Tenant::factory()->create())->create();

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.bulk'), [
                'action' => 'block',
                'ids' => [$theirs->id],
            ]);

        $this->assertNull($theirs->fresh()->blocked_at);
    }

    public function test_a_broadcast_queues_one_job_per_customer_and_skips_blocked(): void
    {
        Bus::fake();

        TenantWhatsApp::factory()->for($this->tenant)->create(['bot_type' => 'order']);

        $first = $this->customer();
        $second = $this->customer();
        $blocked = $this->customer(['blocked_at' => now()]);

        $this->actingAs($this->tenant, 'tenant')
            ->from(route('customers.index'))
            ->post(route('customers.bulk'), [
                'action' => 'broadcast',
                'ids' => [$first->id, $second->id, $blocked->id],
                'text' => 'We are open today',
            ])
            ->assertSessionHas('success');

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
    }

    public function test_select_all_matching_returns_only_the_filtered_ids(): void
    {
        $vip = $this->customer(['total_spent' => '500.00']);
        $this->customer(['total_spent' => '1.00']);

        $this->actingAs($this->tenant, 'tenant')
            ->getJson(route('customers.matching-ids', ['segment' => 'vip']))
            ->assertOk()
            ->assertJson(['ids' => [$vip->id]]);
    }

    // --- Export -------------------------------------------------------------

    public function test_the_export_streams_the_filtered_rows(): void
    {
        $this->customer(['name' => 'Big Spender', 'total_spent' => '500.00']);
        $this->customer(['name' => 'Small Spender', 'total_spent' => '1.00']);

        $response = $this->actingAs($this->tenant, 'tenant')
            ->get(route('customers.export', ['segment' => 'vip']));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Big Spender', $csv);
        $this->assertStringNotContainsString('Small Spender', $csv);
    }
}
