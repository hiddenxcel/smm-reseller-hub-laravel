<?php

namespace App\Services\Assistant;

use App\Models\AssistantKnowledge;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * The system prompt for the website assistant: who it is, and everything it is
 * allowed to claim.
 *
 * Built the same way as ShopContext, which does this for a reseller's own bot.
 * The difference is whose shop it describes — this one sells the platform
 * itself, to people who have not bought yet.
 *
 * That changes what a wrong answer costs. A visitor told the wrong price does
 * not simply get a bad answer: they either arrive at checkout expecting a
 * figure nobody will honour, or they leave believing we are more expensive
 * than we are. So no price is ever written into a knowledge answer — prices
 * are read from the `plans` table on every build, which is the same table
 * checkout charges from.
 */
class PlatformContext
{
    /**
     * How long a built prompt is reused.
     *
     * Prices and knowledge change when somebody edits them, and both busts
     * this cache on save, so the window only ever covers a plan row changed
     * directly in the database.
     */
    private const CACHE_MINUTES = 30;

    private const CACHE_KEY = 'assistant.prompt';

    /**
     * Paths the assistant may link to.
     *
     * A model that invents a URL sends people to a 404, which reads as a
     * broken site rather than a wrong link. Anything not in this list is
     * stripped from the answer before it leaves.
     */
    public const PAGES = [
        '/' => 'Home',
        '/features' => 'What the bots do, feature by feature',
        '/what-we-do' => 'The services we offer',
        '/pricing' => 'Prices for every service, and payment methods',
        '/api-docs' => 'API documentation for developers',
        '/contact' => 'Contact form',
        '/blog' => 'Guides and articles',
        '/register' => 'Create an account and start the setup wizard',
        '/login' => 'Sign in to an existing account',
    ];

    /** Built once per locale and reused; see CACHE_MINUTES. */
    public static function for(string $locale, ?string $page = null): string
    {
        $base = Cache::remember(
            self::CACHE_KEY.'.'.$locale,
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => self::build($locale),
        );

        // Appended rather than cached: the page changes per request, and
        // caching a prompt per page would multiply the entries for one line.
        return $page === null ? $base : $base."\n\n".self::pageContext($page);
    }

    public static function forget(): void
    {
        foreach (['en', 'sw'] as $locale) {
            Cache::forget(self::CACHE_KEY.'.'.$locale);
        }
    }

    private static function build(string $locale): string
    {
        return implode("\n\n", array_filter([
            self::identity(),
            self::rules($locale),
            self::pricing(),
            self::knowledge($locale),
            self::pages(),
        ]));
    }

    private static function identity(): string
    {
        return 'You are the assistant on the SMM ResellersHub website. SMM ResellersHub '
            .'sells WhatsApp automation to people who resell social media marketing '
            .'services: bots that take orders and handle support on their WhatsApp '
            ."number, connected to the SMM panel they already buy from.\n"
            .'You are talking to a visitor who has not signed up yet. Your job is to '
            .'answer what they ask, explain plainly what we sell, and point them at the '
            .'right page — not to push them.';
    }

    /**
     * The constraints, as hard rules.
     *
     * Ordered by how much damage breaking each one does. Inventing a price is
     * first because it is the one that produces a visitor arriving with a
     * number in their head that nobody agreed to.
     */
    private static function rules(string $locale): string
    {
        $hint = $locale === 'sw'
            ? 'The visitor appears to be writing in Kiswahili.'
            : 'The visitor appears to be writing in English.';

        return implode("\n", [
            'RULES — follow these exactly:',
            '1. Never invent a price, a discount, a date, or a number of any kind. Only the figures in PRICING below are real. If a number is not written below, say you are not certain and point them at the pricing page.',
            '2. Only answer from KNOWLEDGE and PRICING below. If the answer is not there — anything about other companies, other tools, other people\'s prices, general marketing advice, or anything at all outside SMM ResellersHub — say plainly that you do not have that in the ResellersHub knowledge base and offer to put them in touch with the team. Do not guess and do not answer from your own general knowledge.',
            '3. Never claim a feature exists unless KNOWLEDGE says so. If asked whether we do something not described below, say you are not sure and offer to check with the team.',
            '4. You cannot create accounts, take payments, look up an order, or access anyone\'s data. For anything like that, point them at the right page or at the team.',
            '5. Never ask for a password, a card number, an API key or a login. There is nothing a visitor should type into this chat that is secret.',
            '6. Keep replies under 70 words and write like a person, not a brochure. No bullet lists unless they asked for a comparison. Never open with "Great question".',
            "7. {$hint} Reply in the language the visitor writes in — Kiswahili if they write Kiswahili, English if they write English. Match them turn by turn; if they switch, you switch.",
            '8. If — and only if — one of the pages in PAGES is genuinely the next step for what they asked, end your reply with a call to action on its own final line, in exactly this form:',
            '   [[CTA:Short label|/path]]',
            '   The label is at most four words. The path must be copied exactly from PAGES. Never write more than one, and never write one for a question that was purely informational.',
        ]);
    }

    /**
     * What we charge, from the table checkout bills from.
     *
     * Every active plan, not only the advertised three: someone asking the
     * assistant about AI Chat has asked a direct question, and answering "I
     * cannot tell you" about a service we sell is worse than the page's
     * reasons for not leading with it.
     */
    private static function pricing(): string
    {
        $plans = Plan::where('status', 'active')->orderBy('sort_order')->get();

        if ($plans->isEmpty()) {
            return 'PRICING: not published yet. If anyone asks about price, say the '
                .'pricing page has the current figures and send them there.';
        }

        $lines = $plans->map(function (Plan $plan) {
            $monthly = self::money((float) $plan->price_monthly, (string) $plan->currency);
            $yearly = self::money((float) $plan->price_yearly, (string) $plan->currency);

            return "- {$plan->name}: {$monthly} per month, or {$yearly} per year. {$plan->description}";
        })->implode("\n");

        // The discount curve is a business rule rather than per-plan data, so
        // it lives in config and is stated here rather than multiplied out —
        // the model must not do arithmetic on prices.
        $terms = collect(config('billing.terms', []))
            ->filter(fn (array $term) => ($term['discount'] ?? 0) > 0)
            ->map(fn (array $term, int $months) => "{$months} months: ".round($term['discount'] * 100).'% off')
            ->implode('; ');

        return implode("\n", array_filter([
            'PRICING (these are exact — never round, never estimate, never convert to another currency):',
            $lines,
            $terms === '' ? null : "Longer terms are discounted — {$terms}. Do not calculate a discounted total yourself; say the discount and send them to the pricing page.",
            'Each service is bought separately. Nobody has to buy all of them.',
        ]));
    }

    private static function knowledge(string $locale): string
    {
        $entries = AssistantKnowledge::live();

        if ($entries->isEmpty()) {
            return 'KNOWLEDGE: empty. Answer nothing about the product — say you cannot '
                .'help yet and offer to put them in touch with the team.';
        }

        $lines = $entries->map(function (AssistantKnowledge $entry) use ($locale) {
            $block = "Q: {$entry->question}\nA: {$entry->answerIn($locale)}";

            $cta = $entry->cta();

            if ($cta !== null) {
                $block .= "\nSuggested CTA: [[CTA:{$cta['label']}|{$cta['url']}]]";
            }

            return $block;
        })->implode("\n\n");

        return "KNOWLEDGE — everything you are allowed to say about SMM ResellersHub:\n\n".$lines;
    }

    private static function pages(): string
    {
        $lines = collect(self::PAGES)
            ->map(fn (string $description, string $path) => "- {$path} — {$description}")
            ->implode("\n");

        return "PAGES you may link to in a CTA (these paths exist; no others do):\n".$lines;
    }

    private static function pageContext(string $page): string
    {
        return "WHERE THEY ARE: the visitor is reading {$page} right now. "
            .'Read anything ambiguous in that light, and do not send them to the page '
            .'they are already on.';
    }

    /** Trailing zeros dropped, so 17.00 reads as $17 rather than $17.00. */
    private static function money(float $amount, string $currency): string
    {
        $formatted = rtrim(rtrim(number_format($amount, 2, '.', ','), '0'), '.');

        return "{$formatted} {$currency}";
    }
}
