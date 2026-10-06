<?php

namespace Tests\Feature;

use App\Jobs\SubmitOrderToPanel;
use App\Models\ApiKey;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What each action answers.
 *
 * The response shapes are pinned deliberately: they are the SMM API v2
 * convention, which client libraries written for other panels already parse.
 * A "tidier" shape here is a broken integration for whoever pointed existing
 * code at this endpoint.
 */
class ApiActionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private BotCustomer $customer;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->customer = BotCustomer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'balance' => '100.00',
        ]);

        [, $this->key] = ApiKey::issue($this->customer);
    }

    private function api(array $payload)
    {
        return $this->postJson('/api/v2', array_merge(['key' => $this->key], $payload));
    }

    // ---- services --------------------------------------------------------

    public function test_services_lists_the_catalogue_in_the_conventional_shape(): void
    {
        BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Instagram Followers',
            'my_price' => '2.0000',
            'min_quantity' => 100,
            'max_quantity' => 5000,
        ]);

        $response = $this->api(['action' => 'services']);

        $response->assertOk()->assertJsonCount(1);
        // Every value a string, including the numbers — that is the convention.
        $response->assertJson([[
            'name' => 'Instagram Followers',
            'rate' => '2.0000',
            'min' => '100',
            'max' => '5000',
        ]]);
    }

    public function test_services_never_reveals_what_the_reseller_pays(): void
    {
        BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'cost_price' => '1.5000',
            'my_price' => '2.0000',
        ]);

        $this->api(['action' => 'services'])
            ->assertOk()
            ->assertJsonMissing(['cost_price' => '1.5000']);
    }

    /**
     * A paused service is absent rather than listed-and-refused: the caller is
     * code, and code that reads a catalogue will order from it.
     */
    public function test_services_omits_paused_and_hidden_services(): void
    {
        BotService::factory()->create(['tenant_id' => $this->tenant->id]);
        BotService::factory()->paused()->create(['tenant_id' => $this->tenant->id]);
        BotService::factory()->hidden()->create(['tenant_id' => $this->tenant->id]);

        $this->api(['action' => 'services'])->assertOk()->assertJsonCount(1);
    }

    // ---- add -------------------------------------------------------------

    public function test_add_places_an_order_and_charges_the_wallet(): void
    {
        Queue::fake();

        $service = BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'my_price' => '2.0000',
            'min_quantity' => 100,
            'max_quantity' => 10000,
        ]);

        $response = $this->api([
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://instagram.com/someone',
            'quantity' => 1000,
        ]);

        $response->assertOk()->assertJsonStructure(['order']);

        // Priced per 1,000 units: 2.0000 * 1000 / 1000 = 2.00.
        $this->assertSame('98.00', (string) $this->customer->fresh()->balance);

        $order = BotOrder::withoutTenantScope()->first();
        $this->assertSame('2.00', (string) $order->amount);
        $this->assertSame($this->customer->id, $order->customer_id);
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_add_costs_the_same_over_the_api_as_over_whatsapp(): void
    {
        Queue::fake();

        // The bot's costOf: my_price * quantity / 1000, at two places.
        $service = BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'my_price' => '3.5000',
            'min_quantity' => 1,
        ]);

        $this->api([
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://example.com/x',
            'quantity' => 250,
        ])->assertOk();

        $this->assertSame('0.87', (string) BotOrder::withoutTenantScope()->first()->amount);
    }

    public function test_add_queues_the_panel_submission_rather_than_waiting_on_it(): void
    {
        Queue::fake();

        $service = BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'min_quantity' => 1,
        ]);

        $this->api([
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://example.com/x',
            'quantity' => 100,
        ])->assertOk();

        Queue::assertPushed(SubmitOrderToPanel::class);
    }

    public function test_add_refuses_when_the_wallet_is_short_and_charges_nothing(): void
    {
        Queue::fake();

        $this->customer->update(['balance' => '1.00']);

        $service = BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'my_price' => '10.0000',
            'min_quantity' => 1,
        ]);

        $this->api([
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://example.com/x',
            'quantity' => 1000,
        ])->assertOk()->assertJson(['error' => 'Not enough balance']);

        $this->assertSame('1.00', (string) $this->customer->fresh()->balance);
        $this->assertSame(0, BotOrder::withoutTenantScope()->count());
        Queue::assertNothingPushed();
    }

    public function test_add_refuses_a_service_from_another_reseller(): void
    {
        $other = BotService::factory()->create();

        $this->api([
            'action' => 'add',
            'service' => $other->id,
            'link' => 'https://example.com/x',
            'quantity' => 1000,
        ])->assertOk()->assertJson(['error' => 'Invalid service']);
    }

    public function test_add_refuses_a_paused_service(): void
    {
        $service = BotService::factory()->paused()->create(['tenant_id' => $this->tenant->id]);

        $this->api([
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://example.com/x',
            'quantity' => 1000,
        ])->assertOk()->assertJson(['error' => 'This service is not available right now']);
    }

    public function test_add_refuses_a_quantity_outside_the_services_range(): void
    {
        $service = BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'min_quantity' => 100,
            'max_quantity' => 1000,
        ]);

        $this->api([
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://example.com/x',
            'quantity' => 50,
        ])->assertOk()->assertJson(['error' => 'Quantity must be between 100 and 1000']);

        $this->assertSame(0, BotOrder::withoutTenantScope()->count());
    }

    /**
     * Approval is a WhatsApp conversation, and an API caller has no way to
     * take part in one — so it is refused rather than left waiting.
     */
    public function test_add_refuses_a_service_that_needs_the_resellers_approval(): void
    {
        $service = BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'requires_approval' => true,
            'min_quantity' => 1,
        ]);

        $this->api([
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://example.com/x',
            'quantity' => 100,
        ])->assertOk()->assertJson(['error' => 'This service cannot be ordered over the API']);
    }

    public function test_add_requires_a_link_and_a_quantity(): void
    {
        $service = BotService::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->api(['action' => 'add', 'service' => $service->id])
            ->assertOk()
            ->assertJsonStructure(['error']);
    }

    // ---- status ----------------------------------------------------------

    public function test_status_reports_one_order(): void
    {
        $order = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'amount' => '5.00',
            'status' => 'Completed',
        ]);

        $this->api(['action' => 'status', 'order' => $order->id])
            ->assertOk()
            ->assertJson([
                'charge' => '5.0000',
                'status' => 'Completed',
                'currency' => 'USD',
            ]);
    }

    /**
     * Panels disagree on wording; the caller sees the convention's vocabulary
     * rather than whichever panel happens to be behind the order.
     */
    public function test_status_normalises_whatever_the_panel_called_it(): void
    {
        foreach ([
            'In progress' => 'In progress',
            'PROCESSING' => 'In progress',
            'COMPLETE' => 'Completed',
            'Canceled' => 'Canceled',
            // The standard status for an order delivered in part.
            'Partially refunded' => 'Partial',
            null => 'Pending',
        ] as $panelSaid => $expected) {
            // Placed with the panel: its word is what is being normalised.
            $order = BotOrder::factory()->submitted()->create([
                'tenant_id' => $this->tenant->id,
                'customer_id' => $this->customer->id,
                'status' => $panelSaid === '' ? null : $panelSaid,
            ]);

            $this->api(['action' => 'status', 'order' => $order->id])
                ->assertOk()
                ->assertJson(['status' => $expected]);
        }
    }

    public function test_status_refuses_another_customers_order(): void
    {
        // Same reseller, different customer — ids are sequential, so this must
        // not be readable by guessing.
        $other = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $other->id,
        ]);

        $this->api(['action' => 'status', 'order' => $order->id])
            ->assertOk()
            ->assertJson(['error' => 'Incorrect order ID']);
    }

    public function test_status_refuses_an_unknown_order(): void
    {
        $this->api(['action' => 'status', 'order' => 999999])
            ->assertOk()
            ->assertJson(['error' => 'Incorrect order ID']);
    }

    // ---- multiple orders -------------------------------------------------

    public function test_a_comma_separated_list_answers_each_id(): void
    {
        $first = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'status' => 'Completed',
        ]);
        $second = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'status' => 'In progress',
        ]);

        $this->api(['action' => 'status', 'orders' => "{$first->id},{$second->id}"])
            ->assertOk()
            ->assertJson([
                (string) $first->id => ['status' => 'Completed'],
                (string) $second->id => ['status' => 'In progress'],
            ]);
    }

    /**
     * An id that does not resolve gets an error entry rather than vanishing —
     * a caller matching the response to its own records needs every key back.
     */
    public function test_an_unknown_id_in_a_list_is_reported_not_dropped(): void
    {
        $order = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        $this->api(['action' => 'status', 'orders' => "{$order->id},999999"])
            ->assertOk()
            ->assertJson(['999999' => ['error' => 'Incorrect order ID']])
            ->assertJsonCount(2);
    }

    public function test_a_list_is_capped(): void
    {
        $ids = implode(',', range(1, 101));

        $this->api(['action' => 'status', 'orders' => $ids])
            ->assertOk()
            ->assertJson(['error' => 'No more than 100 orders per request']);
    }

    // ---- balance ---------------------------------------------------------

    public function test_balance_reports_the_customers_own_wallet(): void
    {
        $this->api(['action' => 'balance'])
            ->assertOk()
            ->assertJson(['balance' => '100.00', 'currency' => 'USD']);
    }

    public function test_balance_reflects_a_spend_immediately(): void
    {
        Queue::fake();

        $service = BotService::factory()->create([
            'tenant_id' => $this->tenant->id,
            'my_price' => '2.0000',
            'min_quantity' => 1,
        ]);

        $this->api([
            'action' => 'add',
            'service' => $service->id,
            'link' => 'https://example.com/x',
            'quantity' => 1000,
        ]);

        $this->api(['action' => 'balance'])->assertOk()->assertJson(['balance' => '98.00']);
    }

    // ---- refill ----------------------------------------------------------

    public function test_refill_is_refused_for_an_order_the_panel_never_took(): void
    {
        $order = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'provider_order_id' => null,
            'status' => 'Completed',
        ]);

        $this->api(['action' => 'refill', 'order' => $order->id])
            ->assertOk()
            ->assertJson(['error' => 'This order cannot be refilled']);
    }

    public function test_refill_is_refused_before_delivery_finishes(): void
    {
        $order = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'provider_order_id' => '5555',
            'status' => 'In progress',
        ]);

        $this->api(['action' => 'refill', 'order' => $order->id])
            ->assertOk()
            ->assertJson(['error' => 'This order cannot be refilled']);
    }

    public function test_refill_refuses_another_customers_order(): void
    {
        $other = BotCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = BotOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $other->id,
            'provider_order_id' => '5555',
            'status' => 'Completed',
        ]);

        $this->api(['action' => 'refill', 'order' => $order->id])
            ->assertOk()
            ->assertJson(['error' => 'Incorrect order ID']);
    }
}
