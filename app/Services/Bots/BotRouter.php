<?php

namespace App\Services\Bots;

use App\Enums\ServiceKey;
use App\Models\BotConversation;
use App\Models\BotMessage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The heart of the platform: one webhook serves every reseller, and this
 * decides whose bot an inbound message belongs to and whether it may run.
 *
 * Meta's payload carries a phone_number_id, which is UNIQUE across the
 * platform — that is the routing key. From there:
 *
 *   1. phone_number_id -> tenant
 *   2. suspended tenants stop immediately
 *   3. pick the bot (order or support)
 *   4. the subscription gate, with a sandbox exception for the reseller's
 *      own test numbers
 *   5. anti-spam, which staff bypass
 *   6. dispatch
 */
class BotRouter
{
    /**
     * Words that open the Support Bot on a number serving both bots. Matched
     * against the first word only, so "refill my order" routes to support but
     * "I need 500 followers" does not.
     */
    private const SUPPORT_WORDS = [
        'support', 'help', 'msaada', 'agent', 'refill', 'status', 'cancel', 'speedup',
    ];

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

        $this->logInbound($tenant->id, $message);

        $target = $this->pickBot($whatsapp->bot_type, $message, $tenant->id);
        $target = $this->applyGate($tenant, $whatsapp->bot_type, $target, $message);

        if ($target === null) {
            $this->notifyPaused($tenant, $whatsapp, $message->from);

            return BotRoute::GateLocked;
        }

        if ($this->isSpam($tenant->id, $target, $message->from)) {
            return BotRoute::SpamBlocked;
        }

        $messenger = $this->messengers->forWhatsApp($whatsapp, $tenant);

        if ($message->providerMessageId !== null) {
            $messenger->markReadWithTyping($message->providerMessageId);
        }

        $this->handlers->for($target, $tenant, $messenger)->handle($message->from, $message->text);

        return $target === 'support' ? BotRoute::HandledSupport : BotRoute::HandledOrder;
    }

    /**
     * Which bot should see this message?
     *
     * A number can serve the order bot, the support bot, or both. For "both",
     * an in-progress conversation wins: a customer who is mid-order and
     * answers "1" must not be re-routed to support just because the text is
     * ambiguous.
     */
    private function pickBot(string $botType, InboundMessage $message, int $tenantId): ?string
    {
        if ($botType === 'order' || $botType === 'support') {
            return $botType;
        }

        if ($botType !== 'both') {
            return null;
        }

        foreach (['order', 'support'] as $candidate) {
            if ($this->hasActiveConversation($tenantId, $message->from, $candidate)) {
                return $candidate;
            }
        }

        $firstWord = mb_strtolower(strtok(trim($message->text), " \t") ?: '');

        return in_array($firstWord, self::SUPPORT_WORDS, true) ? 'support' : 'order';
    }

    /**
     * The subscription gate. Returns the bot to run, or null if nothing may.
     *
     * Sandbox is not live, but a reseller's own test numbers may exercise it —
     * that is how they try a service before paying. And on a "both" number,
     * a locked bot falls back to the other one rather than going silent.
     */
    private function applyGate(Tenant $tenant, string $botType, ?string $target, InboundMessage $message): ?string
    {
        if ($target === null) {
            return null;
        }

        if ($this->mayRun($tenant->id, $target, $message->from)) {
            return $target;
        }

        $other = $target === 'support' ? 'order' : 'support';

        if ($botType === 'both' && $this->mayRun($tenant->id, $other, $message->from)) {
            return $other;
        }

        return null;
    }

    private function mayRun(int $tenantId, string $bot, string $from): bool
    {
        $service = $bot === 'support' ? ServiceKey::SupportBot : ServiceKey::OrderBot;

        if (Subscription::isServiceActive($tenantId, $service)) {
            return true;
        }

        return Subscription::isSandbox($tenantId, $service)
            && BotSettings::isTestNumber($tenantId, $from);
    }

    private function hasActiveConversation(int $tenantId, string $from, string $botType): bool
    {
        $state = BotConversation::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $from)
            ->where('bot_type', $botType)
            ->value('state');

        return $state !== null && $state !== 'IDLE';
    }

    private function isSpam(int $tenantId, string $bot, string $from): bool
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
        $key = "bot:{$tenantId}:{$from}";

        if (RateLimiter::tooManyAttempts($key, $threshold)) {
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

    private function logInbound(int $tenantId, InboundMessage $message): void
    {
        BotMessage::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'customer_phone' => $message->from,
            'direction' => 'in',
            'message' => $message->text,
        ]);
    }
}
