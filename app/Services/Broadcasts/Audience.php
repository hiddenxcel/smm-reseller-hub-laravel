<?php

namespace App\Services\Broadcasts;

use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\TenantTelegramBot;
use App\Models\TenantWhatsApp;
use App\Services\Bots\Telegram\TelegramChat;

/**
 * Who a broadcast can reach, and how.
 *
 * WhatsApp only lets a business send free-form text to someone who wrote to it
 * in the last 24 hours. Everyone else needs an approved template, which this
 * first version does not send, so they are counted separately instead of being
 * silently dropped. Telegram has no such window.
 */
class Audience
{
    public const WINDOW_HOURS = 24;

    public const SEGMENTS = [
        'all' => 'Everyone',
        'recent_7' => 'Active in the last 7 days',
        'recent_30' => 'Active in the last 30 days',
        'recent_90' => 'Active in the last 90 days',
        'with_orders' => 'Customers who have ordered',
        'with_balance' => 'Customers with wallet balance',
    ];

    public const WHATSAPP = 'whatsapp';

    public const TELEGRAM = 'telegram';

    public function __construct(private int $tenantId) {}

    public static function for(int $tenantId): self
    {
        return new self($tenantId);
    }

    /** Is a customer key a Telegram chat rather than a phone number? */
    public static function channelOf(string $phone): string
    {
        return TelegramChat::isKey($phone) ? self::TELEGRAM : self::WHATSAPP;
    }

    public function hasWhatsApp(): bool
    {
        return TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('bot_type', 'order')
            ->where('status', 'active')
            ->exists();
    }

    public function hasTelegram(): bool
    {
        return TenantTelegramBot::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('bot_type', 'order')
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Every customer the segment covers who has not blocked or opted out.
     *
     * @return list<string> customer keys (phone or Telegram key)
     */
    public function phones(string $segment): array
    {
        $query = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereNull('blocked_at')
            ->whereNull('broadcast_opt_out_at');

        match ($segment) {
            'recent_7' => $query->where('last_seen_at', '>=', now()->subDays(7)),
            'recent_30' => $query->where('last_seen_at', '>=', now()->subDays(30)),
            'recent_90' => $query->where('last_seen_at', '>=', now()->subDays(90)),
            'with_orders' => $query->whereHas('orders'),
            'with_balance' => $query->where('balance', '>', 0),
            default => null,
        };

        return $query->pluck('phone')->map(fn ($phone) => (string) $phone)->all();
    }

    /**
     * Customers who wrote to the order bot inside the window, as a lookup set.
     *
     * @return array<string, true>
     */
    public function insideWindow(): array
    {
        return BotMessage::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('direction', 'in')
            ->where('bot_type', 'order')
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->distinct()
            ->pluck('customer_phone')
            ->mapWithKeys(fn ($phone) => [(string) $phone => true])
            ->all();
    }

    /**
     * What a broadcast to this segment would do, for the screen to show first.
     *
     * @return array{total: int, now: int, whatsapp: int, telegram: int, later: int, unreachable: int}
     */
    public function summary(string $segment): array
    {
        $whatsapp = $this->hasWhatsApp();
        $telegram = $this->hasTelegram();
        $window = $this->insideWindow();

        $counts = ['total' => 0, 'now' => 0, 'whatsapp' => 0, 'telegram' => 0, 'later' => 0, 'unreachable' => 0];

        foreach ($this->phones($segment) as $phone) {
            $counts['total']++;

            if (self::channelOf($phone) === self::TELEGRAM) {
                if ($telegram) {
                    $counts['telegram']++;
                    $counts['now']++;
                } else {
                    $counts['unreachable']++;
                }

                continue;
            }

            if (! $whatsapp) {
                $counts['unreachable']++;
            } elseif (isset($window[$phone])) {
                $counts['whatsapp']++;
                $counts['now']++;
            } else {
                $counts['later']++;
            }
        }

        return $counts;
    }

    /** Can this WhatsApp customer be sent free-form text right now? */
    public function isInsideWindow(string $phone): bool
    {
        return BotMessage::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('customer_phone', $phone)
            ->where('direction', 'in')
            ->where('bot_type', 'order')
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->exists();
    }
}
