<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A reseller has written to us — a new ticket, or a reply on an open one.
 *
 * Sent to admins rather than to the reseller. The help desk is a screen someone
 * has to remember to open, and a critical ticket filed on a Friday evening is
 * exactly the one nobody will see until Monday without this.
 *
 * Only the fact and the subject travel: the body can contain a reseller's
 * account details, and support email inboxes are far less controlled than the
 * console the ticket already lives in.
 */
class SupportTicketReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private SupportTicket $ticket,
        private bool $isNew,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $business = $this->ticket->tenant?->business_name ?? 'A reseller';
        $what = $this->isNew ? 'opened a ticket' : 'replied on a ticket';

        $mail = (new MailMessage)
            ->subject(sprintf(
                '[%s] %s%s — %s',
                $this->ticket->reference,
                $this->isNew ? 'New ticket' : 'Reply',
                $this->ticket->priority === 'critical' ? ' (CRITICAL)' : '',
                $this->ticket->subject,
            ))
            ->greeting('Help desk')
            ->line("{$business} {$what}.")
            ->line("**{$this->ticket->subject}**")
            ->line("Category: {$this->ticket->categoryLabel()} · Priority: {$this->ticket->priority}")
            ->action('Open in the console', route('admin.support.show', $this->ticket->id));

        return $mail;
    }
}
