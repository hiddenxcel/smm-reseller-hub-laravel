<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BotCustomer;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Managing resellers from the console.
 *
 * The line this has to hold: nothing consequential happens without a record of
 * who did it. Suspending a business and moving its credit are both reversible
 * in the database and irreversible in the relationship, so the audit row is
 * part of the action, not a side effect of it.
 */
class AdminTenantsTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create(['username' => 'kuza']);
        $this->tenant = Tenant::factory()->create([
            'business_name' => 'Kuza SMM',
            'referral_credit' => 100,
        ]);

        $this->actingAs($this->admin, 'superadmin');
    }

    public function test_the_list_shows_every_reseller(): void
    {
        Tenant::factory()->count(4)->create();

        $this->get('/hx-control/tenants')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Tenants/Index')
                ->has('tenants.data', 5),
        );
    }

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        Tenant::factory()->create(['business_name' => 'Zanzibar Media']);
        Tenant::factory()->suspended()->create(['business_name' => 'Dormant Co']);

        $this->get('/hx-control/tenants?q=Zanzibar')->assertInertia(
            fn (AssertableInertia $page) => $page->has('tenants.data', 1)
                ->where('tenants.data.0.name', 'Zanzibar Media'),
        );

        $this->get('/hx-control/tenants?status=suspended')->assertInertia(
            fn (AssertableInertia $page) => $page->has('tenants.data', 1)
                ->where('tenants.data.0.name', 'Dormant Co'),
        );
    }

    public function test_an_unknown_filter_value_is_ignored_rather_than_failing(): void
    {
        // Filters arrive from the URL, so a stale link must still open the page.
        $this->get('/hx-control/tenants?status=nonsense&sort=DROP+TABLE&per_page=9999')
            ->assertOk();
    }

    public function test_the_detail_page_shows_one_reseller(): void
    {
        $this->get("/hx-control/tenants/{$this->tenant->id}")->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Tenants/Show')
                ->where('tenant.name', 'Kuza SMM')
                // Loose comparison: a whole amount serialises as 100, not 100.0.
                ->where('tenant.referralCredit', fn ($credit) => (float) $credit === 100.0),
        );
    }

    public function test_the_detail_tabs_answer_json(): void
    {
        BotCustomer::factory()->for($this->tenant)->count(2)->create();

        $this->getJson("/hx-control/tenants/{$this->tenant->id}/customers")
            ->assertOk()
            ->assertJsonCount(2, 'customers');
    }

    public function test_one_tenants_data_never_appears_under_another(): void
    {
        $other = Tenant::factory()->create();
        BotCustomer::factory()->for($other)->count(3)->create();
        BotCustomer::factory()->for($this->tenant)->create();

        $this->getJson("/hx-control/tenants/{$this->tenant->id}/customers")
            ->assertJsonCount(1, 'customers');
    }

    public function test_suspending_stops_the_reseller_and_is_recorded(): void
    {
        $this->post("/hx-control/tenants/{$this->tenant->id}/suspend", [
            'reason' => 'chargebacks',
        ])->assertRedirect();

        $this->assertSame('suspended', $this->tenant->fresh()->status);

        $entry = ActivityLog::where('action', 'tenants.suspend')->first();

        $this->assertNotNull($entry);
        $this->assertSame('superadmin', $entry->actor_type);
        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertSame($this->tenant->id, $entry->details['tenant_id']);
        $this->assertSame('chargebacks', $entry->details['reason']);
        $this->assertSame('kuza', $entry->details['actor']);
    }

    public function test_reactivating_puts_the_reseller_back(): void
    {
        $this->tenant->update(['status' => 'suspended']);

        $this->post("/hx-control/tenants/{$this->tenant->id}/activate");

        $this->assertSame('active', $this->tenant->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['action' => 'tenants.activate']);
    }

    public function test_suspending_leaves_customer_wallets_untouched(): void
    {
        $customer = BotCustomer::factory()->for($this->tenant)->create(['balance' => 40]);

        $this->post("/hx-control/tenants/{$this->tenant->id}/suspend");

        // A suspension is a pause. Their customers' money is not ours to move.
        $this->assertSame('40.00', $customer->fresh()->balance);
    }

    public function test_credit_can_be_granted_and_deducted(): void
    {
        $this->post("/hx-control/tenants/{$this->tenant->id}/credit", [
            'delta' => 25.50,
            'reason' => 'goodwill for downtime',
        ])->assertRedirect();

        $this->assertSame('125.50', $this->tenant->fresh()->referral_credit);

        $this->post("/hx-control/tenants/{$this->tenant->id}/credit", [
            'delta' => -25.50,
            'reason' => 'reversing the above',
        ]);

        $this->assertSame('100.00', $this->tenant->fresh()->referral_credit);
    }

    public function test_credit_never_goes_negative(): void
    {
        $this->post("/hx-control/tenants/{$this->tenant->id}/credit", [
            'delta' => -500,
            'reason' => 'clearing the balance',
        ]);

        // Negative credit would be spendable money the reseller does not have.
        $this->assertSame('0.00', $this->tenant->fresh()->referral_credit);
    }

    public function test_moving_credit_requires_a_reason(): void
    {
        $this->post("/hx-control/tenants/{$this->tenant->id}/credit", [
            'delta' => 50,
        ])->assertSessionHasErrors('reason');

        $this->assertSame('100.00', $this->tenant->fresh()->referral_credit);
    }

    public function test_a_credit_adjustment_records_both_sides_of_the_move(): void
    {
        $this->post("/hx-control/tenants/{$this->tenant->id}/credit", [
            'delta' => 25,
            'reason' => 'goodwill',
        ]);

        $entry = ActivityLog::where('action', 'tenants.credit')->first();

        // Cast rather than assertSame: a whole amount round-trips through JSON
        // as an int, and it is the figures that matter here, not their type.
        $this->assertSame(100.0, (float) $entry->details['before']);
        $this->assertSame(125.0, (float) $entry->details['after']);
        $this->assertSame('goodwill', $entry->details['reason']);
    }

    public function test_details_can_be_edited(): void
    {
        $this->patch("/hx-control/tenants/{$this->tenant->id}", [
            'business_name' => 'Kuza Media Ltd',
            'email' => 'hello@kuza.co.tz',
            'phone' => '255700000001',
            'lang' => 'sw',
        ])->assertRedirect();

        $fresh = $this->tenant->fresh();

        $this->assertSame('Kuza Media Ltd', $fresh->business_name);
        $this->assertSame('sw', $fresh->lang);
        $this->assertDatabaseHas('activity_log', ['action' => 'tenants.edit']);
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        Tenant::factory()->create(['email' => 'taken@example.com']);

        $this->patch("/hx-control/tenants/{$this->tenant->id}", [
            'business_name' => 'Kuza SMM',
            'email' => 'taken@example.com',
            'lang' => 'en',
        ])->assertSessionHasErrors('email');
    }

    public function test_a_password_can_be_reset_without_being_logged(): void
    {
        $this->post("/hx-control/tenants/{$this->tenant->id}/password", [
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertRedirect();

        $this->assertTrue(
            Hash::check('a-new-password', $this->tenant->fresh()->password_hash),
        );

        $entry = ActivityLog::where('action', 'tenants.password')->first();

        $this->assertNotNull($entry);
        // The trail records that it happened, never the credential itself.
        $this->assertStringNotContainsString(
            'a-new-password',
            json_encode($entry->details),
        );
    }

    public function test_a_mismatched_password_confirmation_is_refused(): void
    {
        $original = $this->tenant->password_hash;

        $this->post("/hx-control/tenants/{$this->tenant->id}/password", [
            'password' => 'a-new-password',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors('password');

        $this->assertSame($original, $this->tenant->fresh()->password_hash);
    }

    public function test_the_activity_tab_shows_admin_actions_against_this_reseller(): void
    {
        $other = Tenant::factory()->create();

        $this->post("/hx-control/tenants/{$this->tenant->id}/suspend");
        $this->post("/hx-control/tenants/{$other->id}/suspend");

        $response = $this->getJson("/hx-control/tenants/{$this->tenant->id}/activity");

        $actions = collect($response->json('activity'))->pluck('action');

        $this->assertTrue($actions->contains('tenants.suspend'));
        // One suspension each — the other reseller's must not appear here.
        $this->assertSame(1, $actions->filter(fn ($a) => $a === 'tenants.suspend')->count());
    }

    public function test_an_unknown_reseller_is_a_404(): void
    {
        $this->get('/hx-control/tenants/999999')->assertNotFound();
    }
}
