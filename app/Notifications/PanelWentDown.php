<?php

namespace App\Notifications;

use App\Models\TenantPanel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A reseller's panel has stopped answering.
 *
 * Sent to the reseller, not to us: it is their panel, their credentials and
 * their account with that provider, and there is nothing we can do about it
 * from this side. Until they act, orders will keep being accepted and keep
 * failing to reach the provider — which is why this says what to expect
 * rather than only what happened.
 *
 * Sent once, when the panel goes down, not on every check. See PanelHealth.
 */
class PanelWentDown extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private TenantPanel $panel,
        private string $reason,
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
            ->subject("Your panel \"{$this->panel->name}\" is not responding")
            ->greeting('Your panel stopped answering')
            ->line("We could not reach **{$this->panel->name}** just now.")
            ->line("What it said: {$this->reason}")
            ->line('Orders your customers place will still be taken and will keep retrying, so nothing is lost yet — but they will not reach the provider until this panel answers again.')
            ->line('The usual causes are an expired or changed API key, or the provider being down.')
            ->action('Check your panel settings', route('settings', 'panel'))
            ->line('We will email you again when it starts responding.');
    }
}
