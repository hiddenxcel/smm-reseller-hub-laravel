<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\SupportTicket;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A reseller asking the platform for help.
 *
 * The lines this has to hold: a reseller sees their own tickets and nobody
 * else's, an internal note never reaches them, and a closed thread stays
 * closed. Everything else here is convenience; those three are the product.
 */
class SupportCenterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->actingAs($this->tenant);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'subject' => 'Orders are stuck at pending',
            'category' => 'order-bot',
            'priority' => 'high',
            'body' => 'Every order since this morning sits at pending.',
            ...$overrides,
        ];
    }

    public function test_opening_a_ticket_stores_the_first_message_with_it(): void
    {
        $this->post('/help/tickets', $this->payload())->assertRedirect();

        $ticket = SupportTicket::withoutTenantScope()->firstOrFail();

        $this->assertSame($this->tenant->id, $ticket->tenant_id);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('tenant', $ticket->last_reply_by);
        $this->assertSame(
            'Every order since this morning sits at pending.',
            $ticket->messages()->sole()->body,
        );
    }

    public function test_every_ticket_gets_its_own_reference(): void
    {
        $this->post('/help/tickets', $this->payload());
        $this->post('/help/tickets', $this->payload(['subject' => 'Another thing']));

        $references = SupportTicket::withoutTenantScope()->pluck('reference');

        $this->assertCount(2, $references->unique());
        $this->assertStringStartsWith('HX-', $references->first());
    }

    public function test_a_ticket_needs_a_subject_and_a_body(): void
    {
        $this->post('/help/tickets', $this->payload(['subject' => '', 'body' => '']))
            ->assertSessionHasErrors(['subject', 'body']);

        $this->assertSame(0, SupportTicket::withoutTenantScope()->count());
    }

    public function test_an_unknown_category_is_refused(): void
    {
        $this->post('/help/tickets', $this->payload(['category' => 'nonsense']))
            ->assertSessionHasErrors('category');
    }

    public function test_the_list_shows_only_this_resellers_tickets(): void
    {
        SupportTicket::factory()->create(['tenant_id' => $this->tenant->id]);
        SupportTicket::factory()->create();

        $this->get('/help/support')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Help/SupportCenter')
                ->has('tickets', 1),
        );
    }

    public function test_another_resellers_ticket_is_not_reachable(): void
    {
        $other = SupportTicket::factory()->create();

        $this->get("/help/tickets/{$other->id}")->assertNotFound();
    }

    public function test_replying_puts_the_ticket_back_on_the_platform(): void
    {
        $ticket = SupportTicket::factory()->pending()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->post("/help/tickets/{$ticket->id}/reply", ['body' => 'Still broken.'])
            ->assertRedirect();

        $ticket->refresh();

        $this->assertSame('answered', $ticket->status);
        $this->assertSame('tenant', $ticket->last_reply_by);
    }

    /**
     * The alternative — making somebody file a fresh ticket repeating the first
     * — is how a support queue loses the history of a recurring problem.
     */
    public function test_replying_to_a_resolved_ticket_reopens_it(): void
    {
        $ticket = SupportTicket::factory()->resolved()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->post("/help/tickets/{$ticket->id}/reply", ['body' => 'It came back.']);

        $ticket->refresh();

        $this->assertSame('answered', $ticket->status);
        $this->assertNull($ticket->resolved_at);
    }

    public function test_a_closed_ticket_cannot_be_replied_to(): void
    {
        $ticket = SupportTicket::factory()->closed()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->post("/help/tickets/{$ticket->id}/reply", ['body' => 'Hello?'])
            ->assertSessionHas('error');

        $this->assertSame(0, $ticket->messages()->count());
    }

    /**
     * The note is written for other admins. It reaching the browser is a leak
     * whether or not the component renders it.
     */
    public function test_an_internal_note_is_never_sent_to_the_reseller(): void
    {
        $ticket = SupportTicket::factory()->create(['tenant_id' => $this->tenant->id]);

        $ticket->messages()->create([
            'author' => 'tenant',
            'body' => 'My orders are stuck.',
        ]);

        $ticket->messages()->create([
            'author' => 'admin',
            'body' => 'This account has not paid since March.',
            'internal' => true,
        ]);

        $response = $this->get("/help/tickets/{$ticket->id}");

        $response->assertInertia(
            fn (AssertableInertia $page) => $page->has('messages', 1),
        );

        $response->assertDontSee('has not paid since March');
    }

    public function test_admin_replies_are_shown_without_naming_the_admin(): void
    {
        $ticket = SupportTicket::factory()->create(['tenant_id' => $this->tenant->id]);

        $ticket->messages()->create([
            'author' => 'admin',
            'body' => 'We are looking into it.',
        ]);

        $this->get("/help/tickets/{$ticket->id}")->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('messages.0.author_label', 'Support'),
        );
    }

    public function test_contact_details_are_only_offered_once_configured(): void
    {
        $this->get('/help/support')->assertInertia(
            fn (AssertableInertia $page) => $page->where('contacts', []),
        );

        PlatformSetting::put('support_email', 'help@example.com');

        $this->get('/help/support')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('contacts.email', 'help@example.com'),
        );
    }

    public function test_the_support_center_needs_a_signed_in_reseller(): void
    {
        auth()->logout();

        $this->get('/help/support')->assertRedirect('/login');
    }
}
