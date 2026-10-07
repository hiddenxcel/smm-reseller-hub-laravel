<?php

namespace App\Services\Bots;

use App\Models\TenantBotSetting;
use Illuminate\Support\Arr;

/**
 * Per (tenant, bot_type) settings, merged over defaults so a missing key
 * always resolves — resellers only ever store the keys they changed.
 */
class BotSettings
{
    public const DEFAULTS = [
        'commands' => [
            'refill' => true,
            'status' => true,
            'cancel' => true,
            'speedup' => false,
        ],
        'spam' => [
            'enabled' => true,
            'repeat_threshold' => 3,
            'window_minutes' => 5,
            'disable_minutes' => 60,
        ],
        'response' => [
            'show_provider_name' => false,
            'detailed_status' => true,
        ],
        'staff' => [
            // Phone numbers that bypass anti-spam and receive notifications.
            'numbers' => [],
        ],
        'refill' => [
            // Read what a service promises ("30 Days Refill", "No Refill") from
            // its name instead of needing a rule for each one.
            'auto_read' => true,
            // For a service that says nothing: refuse, allow, or human.
            'default' => 'refuse',
            // For an order the bot did not place (made on the panel's own
            // site): its service is unknown, so nothing can be read from it.
            // The panel itself knows if the order may be refilled.
            'unknown_order' => 'allow',
        ],
        'shop' => [
            'currency' => 'USD',
            'lang' => 'en',
            'min_topup' => 1,
            'referral_percent' => 0,
            'binance_pay_id' => '',
            'support_mode' => 'admin',
            'group_url' => '',
            'website_url' => '',
            // Phones allowed to exercise the bot while the service is in sandbox.
            'test_numbers' => [],
            // Wizard steps the reseller chose to come back to later. Held
            // here rather than derived, because skipping is a decision, not
            // a state of the data.
            'skipped_steps' => [],
            // Give the customer their money back when the provider cancels an
            // order or delivers only part of it.
            'auto_refund' => true,
        ],
    ];

    public static function for(int $tenantId, string $botType): array
    {
        $stored = TenantBotSetting::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', $botType)
            ->value('settings');

        return self::mergeDefaults(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function save(int $tenantId, string $botType, array $settings): void
    {
        TenantBotSetting::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenantId, 'bot_type' => $botType],
            ['settings' => $settings],
        );
    }

    public static function isStaff(int $tenantId, string $botType, string $phone): bool
    {
        $numbers = array_map(
            self::digitsOnly(...),
            Arr::get(self::for($tenantId, $botType), 'staff.numbers', []),
        );

        return in_array(self::digitsOnly($phone), $numbers, true);
    }

    /**
     * Is this sender one of the tenant's registered test numbers? Test numbers
     * let a reseller try a sandbox service before paying, so this is what the
     * sandbox gate exception keys on.
     */
    public static function isTestNumber(int $tenantId, string $phone): bool
    {
        $digits = self::digitsOnly($phone);

        if ($digits === '') {
            return false;
        }

        // Either bot's list counts — a reseller testing the order bot should
        // not have to re-add the same number under support.
        foreach (['order', 'support'] as $botType) {
            $numbers = array_map(
                self::digitsOnly(...),
                Arr::get(self::for($tenantId, $botType), 'shop.test_numbers', []),
            );

            if (in_array($digits, $numbers, true)) {
                return true;
            }
        }

        return false;
    }

    private static function digitsOnly(mixed $phone): string
    {
        return preg_replace('/\D/', '', (string) $phone) ?? '';
    }

    /**
     * Stored values override defaults; missing keys keep theirs. Associative
     * sub-arrays merge recursively, but list arrays (staff numbers, test
     * numbers) are replaced wholesale — merging those would resurrect entries
     * the reseller deleted.
     *
     * Stored keys with no default are kept as well. Walking only the defaults
     * would silently discard anything newer than this list, which is a nasty
     * way to lose a setting.
     */
    private static function mergeDefaults(array $defaults, array $stored): array
    {
        $merged = $defaults;

        foreach ($stored as $key => $value) {
            $default = $defaults[$key] ?? null;

            $merged[$key] = is_array($default) && self::isAssoc($default) && is_array($value)
                ? self::mergeDefaults($default, $value)
                : $value;
        }

        return $merged;
    }

    private static function isAssoc(array $array): bool
    {
        return $array !== [] && ! array_is_list($array);
    }
}
