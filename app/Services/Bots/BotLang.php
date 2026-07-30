<?php

namespace App\Services\Bots;

use App\Models\BotCustomer;

/**
 * Translations for what the bot says to a customer.
 *
 * Deliberately separate from the dashboard's locale: a bot conversation has no
 * browser session, so the language comes from the customer's own saved
 * preference, then the reseller's shop default, then English. A Tanzanian
 * reseller can serve a Kiswahili customer and a French one from the same
 * number.
 *
 * Strings live in lang/bot/{locale}.php with {placeholder} markers.
 */
class BotLang
{
    public const DEFAULT = 'en';

    public const SUPPORTED = ['en', 'fr', 'sw', 'tr', 'hi'];

    /** Shown in the in-bot language chooser, each in its own language. */
    public const NAMES = [
        'en' => 'English',
        'fr' => 'Français',
        'sw' => 'Kiswahili',
        'tr' => 'Türkçe',
        'hi' => 'हिन्दी',
    ];

    /** @var array<string, array<string, string>> */
    private static array $strings = [];

    /**
     * Translate a key, substituting {placeholders}. A missing key falls back
     * to English, then to the key itself — a visible key in a message is
     * easier to spot and fix than a blank.
     */
    public static function get(string $locale, string $key, array $replace = []): string
    {
        $locale = self::normalize($locale);

        $text = self::strings($locale)[$key]
            ?? self::strings(self::DEFAULT)[$key]
            ?? $key;

        if ($replace === []) {
            return $text;
        }

        $tokens = [];
        foreach ($replace as $name => $value) {
            $tokens['{'.trim((string) $name, '{}').'}'] = (string) $value;
        }

        return strtr($text, $tokens);
    }

    public static function normalize(?string $locale): string
    {
        return in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT;
    }

    /** The customer's own choice wins; otherwise the reseller's shop default. */
    public static function resolve(?BotCustomer $customer, ?string $shopDefault): string
    {
        if (in_array($customer?->lang, self::SUPPORTED, true)) {
            return $customer->lang;
        }

        return self::normalize($shopDefault);
    }

    /** @internal exposed for tests that need a clean slate */
    public static function flush(): void
    {
        self::$strings = [];
    }

    private static function strings(string $locale): array
    {
        if (! isset(self::$strings[$locale])) {
            $path = lang_path("bot/{$locale}.php");

            self::$strings[$locale] = is_file($path) ? (array) require $path : [];
        }

        return self::$strings[$locale];
    }
}
