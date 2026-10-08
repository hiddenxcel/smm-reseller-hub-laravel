<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The same alert the team was sent on WhatsApp, by email.
 *
 * WhatsApp will only deliver a free-form message to someone who has written to
 * the bot in the last 24 hours, so an alert can quietly fail to arrive. Email
 * has no such rule, which makes it the safety net: by default it is sent only
 * when WhatsApp could not reach everyone.
 */
class StaffAlertEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $undelivered  phone => plain-words reason
     */
    public function __construct(
        private string $message,
        private string $botLabel,
        private array $undelivered,
        private bool $anyStaff,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plain = trim(str_replace('*', '', $this->message));
        $first = trim((string) strtok($plain, "\n"));

        $mail = (new MailMessage)
            ->subject(mb_substr("{$this->botLabel}: {$first}", 0, 120))
            ->greeting("{$this->botLabel} needs your attention")
            ->line($plain);

        if (! $this->anyStaff) {
            $mail->line('You have no staff numbers set up, so nobody was told on WhatsApp.');
        } elseif ($this->undelivered !== []) {
            $mail->line('WhatsApp could not reach everyone on your team:');

            foreach ($this->undelivered as $phone => $reason) {
                $mail->line("- {$phone}: {$reason}");
            }
        }

        return $mail
            ->action('Open the bot', route($this->botLabel === 'Support Bot' ? 'support-bot' : 'order-bot'))
            ->line('You can change who is told, and how, under the bot\'s Settings.');
    }
}
