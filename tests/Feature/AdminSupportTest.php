<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Superadmin;
use App\Models\SupportTicket;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The help desk: the console side of a reseller's support ticket.
 *
 * Unlike the customer ticket queue this one is writable, so what matters here
 * is that a reply is attributed and audited, that a note stays internal, and
 * that a role which may only read cannot answer.
 */
class AdminSupportTest extends TestCase
{
    use RefreshDatabase;

    private Superadmin $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Superadmin::factory()->owner()->create();
        $this->tenant = Tenant::factory()->create();

        $this->actingAs($this->admin, 'superadmin');
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        return SupportTicket::factory()->create([
            'tenant_id' => $this->tenant->id,
            ...$overrides,
        ]);
    }

    public function test_the_queue_lists_tickets_from_every_reseller(): void
    {
        $this->ticket();
        SupportTicket::factory()->create();

        $this->get('/hx-control/support')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Support/Index')
                ->has('tickets', 2),
        );
    }

    /**
     * The tenant scope is empty on the superadmin guard, so a console query that
     * forgets withoutTenantScope() silently returns nothing at all.
     */
    public function test_a_ticket_opens_despite_belonging_to_a_tenant(): void
    {
        $ticket = $this->ticket();

        $this->get("/hx-control/support/{$ticket->id}")->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Support/Show')
                ->where('ticket.reference', $ticket->reference),
        );
    }

    public function test_replying_hands_the_ticket_back_to_the_reseller(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/reply", [
            'body' => 'We have restarted the sync.',
            'internal' => false,
        ])->assertRedirect();

        $ticket->refresh();

        $this->assertSame('pending', $ticket->status);
        $this->assertSame('admin', $ticket->last_reply_by);
        $this->assertNotNull($ticket->first_responded_at);

        $message = $ticket->messages()->sole();
        $this->assertSame($this->admin->id, $message->superadmin_id);
        $this->assertFalse($message->internal);
    }

    /** The number is meant to answer "how long until we first replied?". */
    public function test_the_first_response_time_is_not_overwritten_by_later_replies(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/reply", [
            'body' => 'Looking now.',
            'internal' => false,
        ]);

        $first = $ticket->refresh()->first_responded_at;

        $this->travel(2)->hours();

        $this->post("/hx-control/support/{$ticket->id}/reply", [
            'body' => 'Fixed.',
            'internal' => false,
        ]);

        $this->assertEquals($first, $ticket->refresh()->first_responded_at);
    }

    /**
     * A note is not correspondence. If it moved the ticket to `pending` the
     * queue would stop showing a reseller who is still waiting.
     */
    public function test_an_internal_note_leaves_the_ticket_waiting_on_us(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/reply", [
            'body' => 'Billing says this account is overdue.',
            'internal' => true,
        ])->assertRedirect();

        $ticket->refresh();

        $this->assertSame('open', $ticket->status);
        $this->assertSame('tenant', $ticket->last_reply_by);
        $this->assertNull($ticket->first_responded_at);
        $this->assertTrue($ticket->messages()->sole()->internal);
    }

    public function test_resolving_and_reopening_move_the_status(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/resolve")->assertRedirect();
        $this->assertSame('resolved', $ticket->refresh()->status);
        $this->assertNotNull($ticket->resolved_at);

        $this->post("/hx-control/support/{$ticket->id}/reopen")->assertRedirect();
        $this->assertSame('answered', $ticket->refresh()->status);
        $this->assertNull($ticket->resolved_at);
    }

    public function test_closing_stops_the_reseller_replying(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/close")->assertRedirect();

        $this->assertFalse($ticket->refresh()->acceptsReply());
    }

    public function test_priority_can_be_raised(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/priority", [
            'priority' => 'critical',
        ])->assertRedirect();

        $this->assertSame('critical', $ticket->refresh()->priority);
    }

    public function test_an_unknown_action_is_not_routed(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/delete")->assertNotFound();
    }

    public function test_a_reply_is_written_to_the_audit_trail(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/reply", [
            'body' => 'Done.',
            'internal' => false,
        ]);

        $log = ActivityLog::where('action', 'tickets.reply')->sole();

        $this->assertSame('superadmin', $log->actor_type);
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame($this->tenant->id, $log->details['tenant_id']);
        $this->assertSame($ticket->reference, $log->details['reference']);
    }

    /** A note logged as a reply would have the trail claim we answered somebody. */
    public function test_a_note_is_audited_as_a_note(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/reply", [
            'body' => 'Internal.',
            'internal' => true,
        ]);

        $this->assertSame(1, ActivityLog::where('action', 'tickets.note')->count());
        $this->assertSame(0, ActivityLog::where('action', 'tickets.reply')->count());
    }

    public function test_the_console_names_the_admin_who_replied(): void
    {
        $ticket = $this->ticket();

        $this->post("/hx-control/support/{$ticket->id}/reply", [
            'body' => 'On it.',
            'internal' => false,
        ]);

        $this->get("/hx-control/support/{$ticket->id}")->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('messages.0.author_label', $this->admin->displayName()),
        );
    }

    public function test_the_help_desk_is_closed_to_signed_out_visitors(): void
    {
        auth()->guard('superadmin')->logout();

        $this->get('/hx-control/support')->assertRedirect('/hx-control/login');
    }
}
