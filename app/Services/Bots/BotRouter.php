<?php

namespace App\Services\Bots;

use App\Enums\ServiceKey;
use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The heart of the platform: one webhook serves every reseller, and this
 * decides whose bot an inbound message belongs to and whether it may run.
 *
 * Meta's payload carries a phone_number_id, which is UNIQUE across the
 * platform — that is the routing key. From there:
 *
 *   1. phone_number_id -> tenant and bot
 *   2. suspended tenants stop immediately
 *   3. the subscription gate, with a sandbox exception for the reseller's
 *      own test numbers
 *   4. anti-spam, which staff bypass
 *   5. dispatch
 *
 * A number runs exactly one bot, so there is no guessing at step 1: the
 * number's bot_type IS the answer. Nothing here reads the message text to
 * decide where it goes.
 */
class BotRouter
{
    public function __construct(
        private BotMessengerFactory $messengers,
        private BotHandlerFactory $handlers,
    ) {}

    public function route(InboundMessage $message): BotRoute
    {
        $whatsapp = TenantWhatsApp::findByPhoneNumberId($message->phoneNumberId);

        if ($whatsapp === null) {
            return BotRoute::UnknownNumber;
        }

        $tenant = Tenant::find($whatsapp->tenant_id);

        if ($tenant === null || $tenant->status === 'suspended') {
            return BotRoute::TenantInactive;
        }

        $bot = $whatsapp->bot_type;

        // Logged before the gate and the spam check, so a message that was
        // refused is still on the record — a reseller asking "did they ever
        // message me?" needs the ones we did not answer most of all.
        $this->logInbound($tenant->id, $message, $bot);
        $this->touchCustomer($tenant->id, $message->from);

        // A reseller who blocked this number wants silence, not an
        // explanation — a reply would tell a nuisance they got through.
        if ($this->isBlocked($tenant->id, $message->from)) {
            return BotRoute::SpamBlocked;
        }

        $target = $this->mayRun($tenant->id, $bot, $message->from) ? $bot : null;

        if ($target === null) {
            $this->notifyPaused($tenant, $whatsapp, $message->from);

            return BotRoute::GateLocked;
        }

        if ($this->isSpam($tenant->id, $target, $message->from, $message->text)) {
            return BotRoute::SpamBlocked;
        }

        $this->rememberName((int) $tenant->id, $message);

        $messenger = $this->messengers->forWhatsApp($whatsapp, $tenant);

        if ($message->providerMessageId !== null) {
            $messenger->markReadWithTyping($message->providerMessageId);
        }

        $this->handlers->for($target, $tenant, $messenger)->handle($message->from, $message->text);

        return $target === 'support' ? BotRoute::HandledSupport : BotRoute::HandledOrder;
    }

    /**
     * The subscription gate.
     *
     * Sandbox is not live, but a reseller's own test numbers may exercise it —
     * that is how they try a service before paying.
     */
    private function mayRun(int $tenantId, string $bot, string $from): bool
    {
        $service = $bot === 'support' ? ServiceKey::SupportBot : ServiceKey::OrderBot;

        if (Subscription::isServiceActive($tenantId, $service)) {
            return true;
        }

        return Subscription::isSandbox($tenantId, $service)
            && BotSettings::isTestNumber($tenantId, $from);
    }

    /**
     * Is this sender repeating themselves?
     *
     * What is counted is the same message sent again, not messages in general.
     * Counting every message treated an ordinary order as an attack: choosing a
     * platform, a service, a quantity, a link and confirming is eight messages
     * in a couple of minutes, and the fourth was dropped without a word — the
     * customer just saw the bot stop answering.
     *
     * Once tripped, the sender stays blocked for the configured block time,
     * not merely until the counting window runs out.
     */
    private function isSpam(int $tenantId, string $bot, string $from, string $text = ''): bool
    {
        $settings = BotSettings::for($tenantId, $bot);

        if (! Arr::get($settings, 'spam.enabled', true)) {
            return false;
        }

        if (BotSettings::isStaff($tenantId, $bot, $from)) {
            return false;
        }

        $threshold = (int) Arr::get($settings, 'spam.repeat_threshold', 3);
        $window = (int) Arr::get($settings, 'spam.window_minutes', 5) * 60;
        $blockFor = (int) Arr::get($settings, 'spam.disable_minutes', 60) * 60;

        $blocked = "bot-blocked:{$tenantId}:{$from}";

        if (Cache::has($blocked)) {
            return true;
        }

        // Case and surrounding space do not make a message a different one.
        $key = "bot:{$tenantId}:{$from}:".md5(mb_strtolower(trim($text)));

        if (RateLimiter::tooManyAttempts($key, $threshold)) {
            Cache::put($blocked, true, $blockFor);

            return true;
        }

        RateLimiter::hit($key, $window);

        return false;
    }

    private function notifyPaused(Tenant $tenant, TenantWhatsApp $whatsapp, string $to): void
    {
        // Without a token there is no way to reply, so stay silent.
        if (blank($whatsapp->cloud_api_token_enc)) {
            return;
        }

        $this->messengers->forWhatsApp($whatsapp, $tenant)->sendText(
            $to,
            '⏸️ This service is currently paused. Please try again later.',
            'SUBSCRIPTION_EXPIRED',
        );
    }

    private function logInbound(int $tenantId, InboundMessage $message, string $botType): void
    {
        BotMessage::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'customer_phone' => $message->from,
            'direction' => 'in',
            'message' => $message->text,
            'bot_type' => $botType,
        ]);
    }

    /**
     * Stamp when this number was last heard from.
     *
     * A bare UPDATE, not a read-modify-write, and it deliberately does not
     * create the row: a customer who has never got as far as the order bot has
     * no record yet, and the message log above already holds the fact.
     */
    private function touchCustomer(int $tenantId, string $from): void
    {
        BotCustomer::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('phone', $from)
            ->update(['last_seen_at' => now()]);
    }

    /**
     * Keep the name on the sender's WhatsApp profile as the customer's name, so
     * the bot can greet them by it.
     *
     * Only filled in, never overwritten: once a name is on file (typed by the
     * reseller, or set earlier) it is theirs to change, not WhatsApp's. A first
     * message from someone new creates the customer here, so the very first
     * greeting already has their name.
     */
    private function rememberName(int $tenantId, InboundMessage $message): void
    {
        if ($message->profileName === null) {
            return;
        }

        $customer = BotCustomer::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $message->from],
            [
                'name' => $message->profileName,
                'lang' => Arr::get(BotSettings::for($tenantId, 'order'), 'shop.lang', BotLang::DEFAULT),
            ],
        );

        if (blank($customer->name)) {
            $customer->update(['name' => $message->profileName]);
        }
    }

    private function isBlocked(int $tenantId, string $from): bool
    {
        return BotCustomer::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('phone', $from)
            ->whereNotNull('blocked_at')
            ->exists();
    }
}
