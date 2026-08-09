<?php

namespace App\Notifications;

use App\Models\TenantPanel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A panel that had stopped answering is answering again.
 *
 * PanelWentDown promises this email, which is the whole reason it exists: told
 * only that something broke, a reseller has to keep checking to find out when
 * it is safe to stop worrying. Sent once, on the way back up.
 */
class PanelRecovered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private TenantPanel $panel) {}

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
            ->subject("Your panel \"{$this->panel->name}\" is working again")
            ->greeting('Your panel is back')
            ->line("**{$this->panel->name}** is responding again.")
            ->line('Orders that were waiting have been retried automatically. Any that ran out of attempts while the panel was down are listed on your orders screen and can be retried from there.')
            ->action('Review your orders', route('orders.index'));
    }
}
