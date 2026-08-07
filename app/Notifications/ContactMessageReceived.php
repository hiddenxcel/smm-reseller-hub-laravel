<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody wrote in through the contact form.
 *
 * Unlike a support ticket this has nowhere else to live — there is no console
 * screen holding it — so the mail carries the whole message rather than a
 * pointer to it.
 *
 * replyTo is the sender, so answering is a reply rather than a copy-paste of
 * their address. The From stays ours: sending as the visitor would fail SPF
 * and land the whole thing in spam.
 */
class ContactMessageReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $name,
        private string $email,
        private string $subject,
        private string $body,
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
        return (new MailMessage)
            ->subject("[Contact] {$this->subject}")
            ->replyTo($this->email, $this->name)
            ->greeting('New message from the website')
            ->line("**From:** {$this->name} ({$this->email})")
            ->line("**Subject:** {$this->subject}")
            ->line('---')
            ->line($this->body)
            ->salutation('Reply to this email to answer them directly.');
    }
}
