<?php

namespace App\Notifications;

use App\Models\TenantPanel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A reseller's panel is nearly out of funds.
 *
 * Distinct from PanelWentDown: the panel is answering perfectly, which is what
 * makes this dangerous. Nothing looks wrong until an order is rejected for
 * insufficient funds, and by then the customer has already paid.
 */
class PanelBalanceLow extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private TenantPanel $panel,
        private float $balance,
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
        $currency = $this->panel->balance_currency ?: '';
        $shown = trim($currency.' '.number_format($this->balance, 2));

        return (new MailMessage)
            ->subject("Your panel \"{$this->panel->name}\" is nearly out of funds")
            ->greeting('Your panel balance is running low')
            ->line("**{$this->panel->name}** has {$shown} left.")
            ->line('Your customers can still order, and you will still take their money — but the provider will start rejecting those orders once this runs out, leaving you to refund them.')
            ->action('Top up your panel', route('settings', 'panel'))
            ->line('Topping up is done with your provider directly, not here.');
    }
}
