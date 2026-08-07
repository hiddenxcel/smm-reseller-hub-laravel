<?php

namespace App\Notifications;

use App\Models\PlatformSetting;
use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * We answered a reseller's support ticket.
 *
 * The gap this closes: before it, a reply only existed on a screen the reseller
 * had no reason to open. Somebody who filed a critical ticket at midnight had
 * no way of knowing it had been answered except by checking, so they went back
 * to messaging whoever they had a number for — which is the behaviour the
 * Support Center was built to replace.
 *
 * Queued because a reply must not wait on SMTP. A mail server that is slow or
 * down would otherwise hold the admin's request open and, on a timeout, lose
 * the reply that had already been written.
 */
class SupportTicketReplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private SupportTicket $ticket) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Deliberately short, and deliberately not the reply itself.
     *
     * The thread is the record: pasting the body into an email invites the
     * reseller to answer by replying to the email, which goes nowhere and looks
     * to them like being ignored a second time.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $company = (string) PlatformSetting::get('company_name', 'Resellers Hub');

        return (new MailMessage)
            ->subject("[{$this->ticket->reference}] We have replied — {$this->ticket->subject}")
            ->greeting("Hello {$notifiable->business_name},")
            ->line("We have replied to your support ticket {$this->ticket->reference}.")
            ->line("**{$this->ticket->subject}**")
            ->action('Read the reply', route('help.tickets.show', $this->ticket->id))
            ->line('Reply on the ticket itself so the whole conversation stays in one place.')
            ->salutation("— {$company} Support");
    }
}
