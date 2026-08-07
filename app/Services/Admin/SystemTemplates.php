<?php

namespace App\Services\Admin;

use App\Models\ResponseTemplate;
use App\Models\Tenant;
use App\Services\Bots\BotLang;

/**
 * The wording every reseller starts with.
 *
 * ResponseTemplate::resolve() already reads a tenant row before falling back to
 * the platform row — `tenant_id NULL` has meant "platform default" since the
 * table was created. This class is the screen for those NULL rows, so it is
 * wiring rather than new machinery.
 *
 * The consequence worth stating: editing one of these changes what every
 * reseller who has NOT overridden that key sends to their customers, in the
 * language chosen, the moment it is saved. A reseller who did override it keeps
 * their wording. That is why the count of overrides travels with each row —
 * "how many people will actually see this change" is the first question.
 *
 * Keys are not free-form. A key nothing sends would let someone carefully write
 * a message that never appears, so the list mirrors what the handlers pass to
 * sendText().
 */
class SystemTemplates
{
    /**
     * Every template key a bot actually sends, with what it is for.
     *
     * The support bot's own screen carries the same list for the tenant-facing
     * version; the order bot's keys are added here because the platform sets
     * defaults for both.
     */
    public const KEYS = [
        // Support bot
        'SUPPORT_MENU' => ['bot' => 'support', 'about' => 'The Quick Menu, sent when a conversation opens.'],
        'HUMAN_HANDOFF' => ['bot' => 'support', 'about' => 'Confirmation that a person is taking over.'],
        'STATUS_SUCCESS' => ['bot' => 'support', 'about' => 'An order status the customer asked for.'],
        'NOT_FOUND' => ['bot' => 'support', 'about' => 'The order ID did not match anything.'],
        'REFILL_SUCCESS' => ['bot' => 'support', 'about' => 'A refill was submitted to the panel.'],
        'REFILL_NO_GUARANTEE' => ['bot' => 'support', 'about' => 'The service carries no refill guarantee.'],
        'REFILL_ERROR' => ['bot' => 'support', 'about' => 'The panel refused the refill.'],
        'CANCEL_SUCCESS' => ['bot' => 'support', 'about' => 'A cancellation request was logged.'],
        'CANCEL_INVALID' => ['bot' => 'support', 'about' => 'The order could not be found to cancel.'],
        'SPEEDUP_SUCCESS' => ['bot' => 'support', 'about' => 'A speed-up request was logged.'],
        'PARTIAL_LOGGED' => ['bot' => 'support', 'about' => 'A partial or fake-completion report was logged.'],
        'TOPUP_HELP' => ['bot' => 'support', 'about' => 'What to send when a top-up did not arrive.'],

        // Order bot
        'WELCOME' => ['bot' => 'order', 'about' => 'First message a new customer receives.'],
        'ORDER_PLACED' => ['bot' => 'order', 'about' => 'An order was accepted and sent to the panel.'],
        'ORDER_FAILED' => ['bot' => 'order', 'about' => 'The panel refused the order.'],
        'BALANCE_LOW' => ['bot' => 'order', 'about' => 'Wallet balance will not cover the order.'],
        'TOPUP_SUCCESS' => ['bot' => 'order', 'about' => 'A wallet top-up was confirmed.'],
    ];

    /**
     * Every key in every language, with the platform default and how many
     * resellers have overridden it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(?string $bot = null, ?string $lang = null): array
    {
        $lang = BotLang::normalize($lang);

        $defaults = ResponseTemplate::whereNull('tenant_id')
            ->where('lang', $lang)
            ->pluck('content', 'template_key');

        $overrides = ResponseTemplate::whereNotNull('tenant_id')
            ->where('lang', $lang)
            ->selectRaw('template_key, count(*) as total')
            ->groupBy('template_key')
            ->pluck('total', 'template_key');

        $rows = [];

        foreach (self::KEYS as $key => $meta) {
            if ($bot !== null && $meta['bot'] !== $bot) {
                continue;
            }

            $stored = $defaults[$key] ?? null;

            $rows[] = [
                'key' => $key,
                'bot' => $meta['bot'],
                'about' => $meta['about'],
                'lang' => $lang,
                // What the platform row says, if one has been written.
                'content' => $stored,
                // What the bot falls back to when no row exists at all: the
                // translation file. Shown so an admin can see what they are
                // replacing before they replace it.
                'builtIn' => BotLang::get($lang, $key),
                'isSet' => $stored !== null,
                'overriddenBy' => (int) ($overrides[$key] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Write or clear a platform default.
     *
     * Clearing deletes the row rather than blanking it: an empty string would
     * be a real override that sends nothing, while a missing row correctly
     * falls through to the translation file.
     */
    public static function save(string $key, string $lang, ?string $content): void
    {
        $lang = BotLang::normalize($lang);

        if ($content === null || trim($content) === '') {
            ResponseTemplate::whereNull('tenant_id')
                ->where('template_key', $key)
                ->where('lang', $lang)
                ->delete();

            AdminAudit::record('templates.clear', [
                'key' => $key,
                'lang' => $lang,
                'affects' => self::inheritingCount($key, $lang),
            ]);

            return;
        }

        ResponseTemplate::updateOrCreate(
            ['tenant_id' => null, 'template_key' => $key, 'lang' => $lang],
            ['content' => $content, 'is_default' => true],
        );

        AdminAudit::record('templates.save', [
            'key' => $key,
            'lang' => $lang,
            // The number that matters: resellers who have not overridden this
            // key will start sending the new wording immediately.
            'affects' => self::inheritingCount($key, $lang),
        ]);
    }

    public static function isKnownKey(string $key): bool
    {
        return array_key_exists($key, self::KEYS);
    }

    /** @return array<int, array{code: string, name: string}> */
    public static function languages(): array
    {
        return array_map(
            fn (string $code) => ['code' => $code, 'name' => BotLang::NAMES[$code] ?? $code],
            BotLang::SUPPORTED,
        );
    }

    /**
     * How many resellers would feel a change to this key.
     *
     * Everyone except those who wrote their own version of it. Counted from
     * tenants rather than from templates, since a reseller with no row at all
     * inherits just as much as one with rows for other keys.
     */
    private static function inheritingCount(string $key, string $lang): int
    {
        $overriding = ResponseTemplate::whereNotNull('tenant_id')
            ->where('template_key', $key)
            ->where('lang', $lang)
            ->distinct()
            ->count('tenant_id');

        return max(Tenant::count() - $overriding, 0);
    }
}
