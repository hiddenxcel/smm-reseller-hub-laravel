<?php

namespace App\Services\Support;

use App\Models\BotMessage;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Bots\BotMessengerFactory;
use App\Services\Customers\ActionOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A person answering a customer from the Support inbox.
 *
 * Two things have to happen together and neither is useful alone: the message
 * goes out over WhatsApp, and it is written into the ticket thread so the next
 * person to open the conversation can see what was already said. The send is
 * attempted first — a `ticket_messages` row claiming staff replied, when Meta
 * refused the message, is worse than no row.
 *
 * Replying also claims the conversation if it was not claimed already. Staff
 * typing into the box is the same statement as the customer pressing 5: from
 * here a person is answering, and the bot must not talk over them.
 */
class SupportReply
{
    public function __construct(
        private Tenant $tenant,
        private BotMessengerFactory $messengers,
    ) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant, app(BotMessengerFactory::class));
    }

    public function send(Ticket $ticket, string $text): ActionOutcome
    {
        $text = trim($text);

        if ($text === '') {
            return ActionOutcome::failed('Write something to send.');
        }

        $phone = $ticket->customer_identifier;

        if (blank($phone)) {
            return ActionOutcome::failed('This ticket has no phone number to reply to.');
        }

        if (! $this->withinWindow($phone)) {
            return ActionOutcome::failed(
                'WhatsApp only allows a free-form reply within 24 hours of the customer\'s '
                .'last message, and this one has gone quiet for longer. Wait until they write in again.'
            );
        }

        $whatsapp = $this->supportNumber();

        if ($whatsapp === null) {
            return ActionOutcome::failed('No WhatsApp number is connected to send from.');
        }

        $sent = $this->messengers
            ->forWhatsApp($whatsapp, $this->tenant)
            ->sendText($phone, $text, 'SUPPORT_REPLY');

        if (! $sent) {
            return ActionOutcome::failed('WhatsApp refused the message. Check the number is still connected.');
        }

        // The thread row and the handoff are one fact — staff are now on this
        // conversation — so a failure between them must not leave the bot
        // answering alongside a reply the customer has already received.
        DB::transaction(function () use ($ticket, $text) {
            TicketMessage::create([
                'ticket_id' => $ticket->id,
                'sender' => 'staff',
                'message' => $text,
            ]);

            $ticket->forceFill([
                'handed_over_at' => $ticket->handed_over_at ?? now(),
                // A resolved ticket that gets a fresh reply is open again.
                'status' => in_array($ticket->status, ['resolved', 'closed'], true) ? 'open' : $ticket->status,
            ])->save();
        });

        return ActionOutcome::ok('Reply sent.');
    }

    /**
     * Is this customer still inside Meta's 24-hour window?
     *
     * Read from the message log rather than the ticket's own stamp: a customer
     * may have written to the order bot more recently than to this ticket, and
     * the window belongs to the phone number, not to one conversation.
     */
    public function withinWindow(string $phone): bool
    {
        $lastInbound = BotMessage::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_phone', $phone)
            ->where('direction', 'in')
            ->max('created_at');

        return $lastInbound !== null
            && Carbon::parse($lastInbound)->gt(Carbon::now()->subHours(Ticket::REPLY_WINDOW_HOURS));
    }

    /**
     * A number to send from.
     *
     * The support bot's own number first: this conversation started there, and
     * a reply arriving from the order number would read as a different sender.
     * Falls back to any connected number rather than refusing outright — a
     * reseller running one number still needs to answer.
     */
    public function supportNumber(): ?TenantWhatsApp
    {
        $numbers = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('cloud_api_token_enc')
            ->get();

        return $numbers->firstWhere('bot_type', 'support') ?? $numbers->first();
    }
}
