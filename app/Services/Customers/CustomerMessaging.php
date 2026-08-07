<?php

namespace App\Services\Customers;

use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotMessengerFactory;
use Illuminate\Support\Carbon;

/**
 * Sending a customer a message from the dashboard.
 *
 * The constraint that shapes all of this is Meta's: outside a 24-hour window
 * that opens when the customer last wrote in, free-form messages are refused.
 * Only pre-approved templates get through, and this platform has none
 * registered — so a send outside the window would fail at the API and the
 * reseller would be left wondering why.
 *
 * Rather than let that happen quietly, the window is checked here and the
 * reseller is told before they type.
 */
class CustomerMessaging
{
    /** Meta's customer service window, in hours. */
    public const WINDOW_HOURS = 24;

    public function __construct(
        private Tenant $tenant,
        private BotMessengerFactory $messengers,
    ) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant, app(BotMessengerFactory::class));
    }

    /**
     * Is this customer still inside the reply window?
     *
     * Read from the message log rather than `last_seen_at`, because it is
     * specifically the last INBOUND message that opens the window — the bot
     * talking does not extend it.
     */
    public function isWithinWindow(BotCustomer $customer): bool
    {
        $lastInbound = BotMessage::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_phone', $customer->phone)
            ->where('direction', 'in')
            ->max('created_at');

        if ($lastInbound === null) {
            return false;
        }

        return Carbon::parse($lastInbound)->gt(Carbon::now()->subHours(self::WINDOW_HOURS));
    }

    /**
     * When the window closes, or null if it is already shut / never opened.
     */
    public function windowClosesAt(BotCustomer $customer): ?Carbon
    {
        $lastInbound = BotMessage::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_phone', $customer->phone)
            ->where('direction', 'in')
            ->max('created_at');

        if ($lastInbound === null) {
            return null;
        }

        $closes = Carbon::parse($lastInbound)->addHours(self::WINDOW_HOURS);

        return $closes->isFuture() ? $closes : null;
    }

    public function send(BotCustomer $customer, string $text): ActionOutcome
    {
        if ($customer->blocked_at !== null) {
            return ActionOutcome::failed('That customer is blocked. Unblock them first.');
        }

        $text = trim($text);

        if ($text === '') {
            return ActionOutcome::failed('Write something to send.');
        }

        if (! $this->isWithinWindow($customer)) {
            return ActionOutcome::failed(
                'WhatsApp only allows a free-form reply within 24 hours of the customer\'s '
                .'last message, and this one has gone quiet for longer. Wait until they write in again.'
            );
        }

        $whatsapp = $this->orderNumber();

        if ($whatsapp === null) {
            return ActionOutcome::failed('No WhatsApp number is connected to send from.');
        }

        // The messenger logs the outbound message itself, so the conversation
        // in the slide-over shows it without anything extra here.
        $sent = $this->messengers
            ->forWhatsApp($whatsapp, $this->tenant)
            ->sendText($customer->phone, $text, 'MANUAL');

        return $sent
            ? ActionOutcome::ok('Message sent.')
            : ActionOutcome::failed('WhatsApp refused the message. Check the number is still connected.');
    }

    /**
     * A number to send from.
     *
     * Preference goes to the order bot's number: that is the one a shopper
     * knows, and a reply arriving from the support number would read as coming
     * from a stranger.
     */
    public function orderNumber(): ?TenantWhatsApp
    {
        $numbers = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('cloud_api_token_enc')
            ->get();

        return $numbers->firstWhere('bot_type', 'order') ?? $numbers->first();
    }
}
