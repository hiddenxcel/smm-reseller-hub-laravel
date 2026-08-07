<?php

namespace App\Services\Support;

use App\Models\Superadmin;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Notifications\SupportTicketReceived;
use App\Notifications\SupportTicketReplied;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Opening and answering platform support tickets.
 *
 * Both sides of the conversation route through here so the status rules exist
 * once. They are easy to get subtly wrong in two places: a reply has to move
 * the ticket in opposite directions depending on who sent it, and "who is this
 * waiting on?" is the only question the queue is really asking.
 */
class SupportTickets
{
    public function __construct(private SupportTicket $ticket) {}

    public static function for(SupportTicket $ticket): self
    {
        return new self($ticket);
    }

    /**
     * File a new ticket, with its opening message.
     *
     * In a transaction because a ticket with no message is not a ticket — the
     * thread screen would render an empty page and the admin would have nothing
     * to answer.
     *
     * @param  array<string, mixed>  $data
     */
    public static function open(Tenant $tenant, array $data): SupportTicket
    {
        $ticket = DB::transaction(function () use ($tenant, $data) {
            $ticket = SupportTicket::withoutTenantScope()->create([
                'reference' => SupportTicket::makeReference(),
                'tenant_id' => $tenant->id,
                'subject' => $data['subject'],
                'category' => $data['category'],
                'priority' => $data['priority'],
                'status' => 'open',
                'last_reply_by' => 'tenant',
                'last_reply_at' => now(),
            ]);

            $ticket->messages()->create([
                'author' => 'tenant',
                'body' => $data['body'],
            ]);

            return $ticket;
        });

        // After the transaction, not inside it: a queued notification picked up
        // before the commit lands would look up a ticket that does not exist
        // yet, and a mail failure must not roll back a ticket we accepted.
        static::alertAdmins($ticket->setRelation('tenant', $tenant), isNew: true);

        return $ticket;
    }

    /**
     * The reseller writes on their own thread.
     *
     * A reply to a resolved ticket reopens it: the alternative is telling
     * somebody whose problem came back that they must file a second ticket
     * repeating the first. `resolved_at` is cleared with it, so reporting does
     * not count a reopened ticket as settled.
     */
    public function replyAsTenant(string $body): SupportTicketMessage
    {
        $message = DB::transaction(function () use ($body) {
            $message = $this->ticket->messages()->create([
                'author' => 'tenant',
                'body' => $body,
            ]);

            $this->ticket->forceFill([
                'status' => 'answered',
                'resolved_at' => null,
                'last_reply_by' => 'tenant',
                'last_reply_at' => now(),
            ])->save();

            return $message;
        });

        static::alertAdmins($this->ticket, isNew: false);

        return $message;
    }

    /**
     * An admin answers, or leaves a note for other admins.
     *
     * An internal note deliberately changes nothing about the ticket's state:
     * the reseller was not written to, so the ticket is still waiting on us and
     * the queue must keep showing it.
     */
    public function replyAsAdmin(int $adminId, string $body, bool $internal = false): SupportTicketMessage
    {
        $message = DB::transaction(function () use ($adminId, $body, $internal) {
            $message = $this->ticket->messages()->create([
                'superadmin_id' => $adminId,
                'author' => 'admin',
                'body' => $body,
                'internal' => $internal,
            ]);

            if ($internal) {
                return $message;
            }

            $this->ticket->forceFill([
                'status' => 'pending',
                'last_reply_by' => 'admin',
                'last_reply_at' => now(),
                // Only the first real reply counts: this is the number that
                // answers "how long do resellers wait to hear from us?"
                'first_responded_at' => $this->ticket->first_responded_at ?? now(),
            ])->save();

            return $message;
        });

        // A note is not correspondence — telling the reseller we replied when
        // we only wrote to ourselves is the one mistake this must never make.
        if (! $internal) {
            $this->ticket->tenant?->notify(new SupportTicketReplied($this->ticket));
        }

        return $message;
    }

    public function resolve(): void
    {
        $this->ticket->forceFill([
            'status' => 'resolved',
            'resolved_at' => now(),
        ])->save();
    }

    /** Locked. Only an admin can do this, and a reply can no longer reopen it. */
    public function close(): void
    {
        $this->ticket->forceFill([
            'status' => 'closed',
            'resolved_at' => $this->ticket->resolved_at ?? now(),
        ])->save();
    }

    public function reopen(): void
    {
        $this->ticket->forceFill([
            'status' => 'answered',
            'resolved_at' => null,
        ])->save();
    }

    public function setPriority(string $priority): void
    {
        $this->ticket->forceFill(['priority' => $priority])->save();
    }

    /**
     * Tell the people who can answer that something is waiting.
     *
     * Every active admin who may work tickets, rather than one owner: a queue
     * whose only alert goes to somebody on leave is a queue nobody answers.
     * Disabled accounts are excluded — they cannot sign in to act on it.
     *
     * Failures are swallowed deliberately. A ticket that was accepted must stay
     * accepted even when the mail host is refusing connections; the ticket is
     * still in the console, which is the record that matters.
     */
    private static function alertAdmins(SupportTicket $ticket, bool $isNew): void
    {
        $admins = Superadmin::query()
            ->where('status', 'active')
            ->whereNotNull('email')
            ->get()
            ->filter(fn (Superadmin $admin) => $admin->can('tickets.manage'));

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new SupportTicketReceived($ticket, $isNew));
    }
}
