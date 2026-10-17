<?php

namespace App\Services\Broadcasts;

use App\Models\BotCustomer;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotSettings;
use Illuminate\Support\Arr;

/**
 * A customer's way out of announcements, and back in: STOP and SUBSCRIBE.
 *
 * Handled before anything else in the bot, so the word never reaches an order
 * flow, and respected even when the shop's subscription has lapsed.
 */
class OptOut
{
    private const STOP = ['stop', 'unsubscribe'];

    private const START = ['subscribe'];

    /** @return 'stop'|'subscribe'|null */
    public static function command(string $text): ?string
    {
        $word = mb_strtolower(trim($text));

        return match (true) {
            in_array($word, self::STOP, true) => 'stop',
            in_array($word, self::START, true) => 'subscribe',
            default => null,
        };
    }

    /** Record the choice and return what to tell the customer. */
    public static function apply(int $tenantId, string $from, string $command, ?string $profileName): string
    {
        $shopLang = Arr::get(BotSettings::for($tenantId, 'order'), 'shop.lang', BotLang::DEFAULT);

        $customer = BotCustomer::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $from],
            ['name' => $profileName, 'lang' => $shopLang],
        );

        $customer->forceFill([
            'broadcast_opt_out_at' => $command === 'stop' ? now() : null,
        ])->save();

        return BotLang::get(
            (string) ($customer->lang ?: $shopLang),
            $command === 'stop' ? 'broadcast_stopped' : 'broadcast_resumed',
        );
    }
}
