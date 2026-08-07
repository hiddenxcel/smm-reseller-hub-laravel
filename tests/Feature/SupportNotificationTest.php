<?php

namespace Tests\Feature;

use App\Models\Superadmin;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Notifications\SupportTicketReceived;
use App\Notifications\SupportTicketReplied;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Telling people a support ticket moved.
 *
 * The gap this closes is the one that made the Support Center only half a
 * feature: a reply nobody is told about is a reply nobody reads. What must hold
 * is that a real reply notifies and an internal note never does — telling a
 * reseller we answered when we only wrote to ourselves is worse than silence.
 */
class SupportNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Superadmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->tenant = Tenant::factory()->create();
        $this->admin = Superadmin::factory()->owner()->create();
    }

    public function test_opening_a_ticket_alerts_the_admins(): void
    {
        $this->actingAs($this->tenant)->post('/help/tickets', [
            'subject' => 'Bot stopped replying',
            'category' => 'order-bot',
            'priority' => 'critical',
            'body' => 'Nothing has gone out since noon.',
        ]);

        Notification::assertSentTo(
            $this->admin,
            SupportTicketReceived::class,
        );
    }

    public function test_a_reseller_reply_alerts_the_admins(): void
    {
        $ticket = SupportTicket::factory()->pending()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($this->tenant)
            ->post("/help/tickets/{$ticket->id}/reply", ['body' => 'Still broken.']);

        Notification::assertSentTo($this->admin, SupportTicketReceived::class);
    }

    /** Nobody who cannot act on it should be woken by it. */
    public function test_a_disabled_admin_is_not_alerted(): void
    {
        $disabled = Superadmin::factory()->owner()->create(['status' => 'disabled']);

        $this->actingAs($this->tenant)->post('/help/tickets', [
            'subject' => 'A question',
            'category' => 'other',
            'priority' => 'low',
            'body' => 'Hello.',
        ]);

        Notification::assertNotSentTo($disabled, SupportTicketReceived::class);
    }

    public function test_an_admin_reply_emails_the_reseller(): void
    {
        $ticket = SupportTicket::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->admin, 'superadmin')
            ->post("/hx-control/support/{$ticket->id}/reply", [
                'body' => 'We have restarted the sync.',
                'internal' => false,
            ]);

        Notification::assertSentTo($this->tenant, SupportTicketReplied::class);
    }

    /**
     * The note is written for other admins. Emailing the reseller "we have
     * replied" when nothing was said to them is the worst outcome here: they
     * open the thread and find nothing new.
     */
    public function test_an_internal_note_never_emails_the_reseller(): void
    {
        $ticket = SupportTicket::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->admin, 'superadmin')
            ->post("/hx-control/support/{$ticket->id}/reply", [
                'body' => 'This account is overdue.',
                'internal' => true,
            ]);

        Notification::assertNothingSentTo($this->tenant);
    }

    public function test_the_email_links_to_the_ticket_and_hides_the_reply_body(): void
    {
        $ticket = SupportTicket::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->admin, 'superadmin')
            ->post("/hx-control/support/{$ticket->id}/reply", [
                'body' => 'The secret internal workaround is X.',
                'internal' => false,
            ]);

        Notification::assertSentTo(
            $this->tenant,
            SupportTicketReplied::class,
            function (SupportTicketReplied $notification) use ($ticket) {
                $mail = $notification->toMail($this->tenant)->toArray();

                $this->assertStringContainsString($ticket->reference, $mail['subject']);
                $this->assertSame(
                    route('help.tickets.show', $ticket->id),
                    $mail['actionUrl'],
                );

                // The thread is the record; the body must not travel by email.
                $this->assertStringNotContainsString(
                    'secret internal workaround',
                    implode(' ', [...$mail['introLines'], ...$mail['outroLines']]),
                );

                return true;
            },
        );
    }

    public function test_the_badge_counts_tickets_we_answered_last(): void
    {
        SupportTicket::factory()->pending()->create(['tenant_id' => $this->tenant->id]);
        SupportTicket::factory()->pending()->create(['tenant_id' => $this->tenant->id]);
        SupportTicket::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->tenant)->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->where('supportUnread', 2),
        );
    }

    public function test_the_badge_ignores_other_resellers_tickets(): void
    {
        SupportTicket::factory()->pending()->create();

        $this->actingAs($this->tenant)->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->where('supportUnread', 0),
        );
    }

    /** Replying is the moment the reseller has demonstrably read it. */
    public function test_replying_clears_the_badge(): void
    {
        $ticket = SupportTicket::factory()->pending()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($this->tenant)
            ->post("/help/tickets/{$ticket->id}/reply", ['body' => 'Thanks.']);

        $this->actingAs($this->tenant)->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->where('supportUnread', 0),
        );
    }
}
