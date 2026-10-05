<?php

namespace App\Services\Assistant;

use App\Models\AssistantKnowledge;
use App\Models\BlogPost;
use App\Models\Plan;
use App\Services\Bots\BotLang;
use App\Services\Onboarding\OnboardingStep;
use App\Services\Payments\Gateway;
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
 *
 * Most of what it knows is not written here but read from the code that does
 * the work: the languages the bots speak, the payment gateways, the setup
 * steps. A sentence typed into a prompt goes stale the day somebody adds a
 * gateway; a sentence derived from the list of gateways cannot.
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

    /** How many guides the assistant is shown, newest first. */
    private const BLOG_POSTS = 12;

    /**
     * Paths the assistant may link to.
     *
     * A model that invents a URL sends people to a 404, which reads as a
     * broken site rather than a wrong link. Anything not in this list — or
     * not a published guide, see allowsPath() — is stripped from the answer
     * before it leaves.
     */
    public const PAGES = [
        '/' => 'Home',
        '/try' => 'Try the real bot in a practice chat on a phone screen — no signup, nothing is charged or saved',
        '/features' => 'What the bots do, feature by feature',
        '/what-we-do' => 'The services we offer',
        '/pricing' => 'Prices for every service, and payment methods',
        '/api-docs' => 'API documentation for developers',
        '/contact' => 'Contact form',
        '/blog' => 'Guides and articles',
        '/register' => 'Create an account and start the setup wizard',
        '/login' => 'Sign in to an existing account',
    ];

    /** Built once per language base and reused; see CACHE_MINUTES. */
    public static function for(string $locale, ?string $page = null, ?string $language = null): string
    {
        $base = Cache::remember(
            self::CACHE_KEY.'.'.$locale,
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => self::build($locale),
        );

        // Appended rather than cached: these change per request, and caching a
        // prompt per page and per language would multiply the entries for a
        // line or two.
        return implode("\n\n", array_filter([
            $base,
            $language === null ? null : self::languageContext($language),
            $page === null ? null : self::pageContext($page),
        ]));
    }

    public static function forget(): void
    {
        foreach (['en', 'sw'] as $locale) {
            Cache::forget(self::CACHE_KEY.'.'.$locale);
        }
    }

    /**
     * Whether a link may be offered: one of the fixed pages, or a published
     * guide. Guides are looked up rather than listed in the constant because
     * they come and go without anybody touching this file.
     */
    public static function allowsPath(string $path): bool
    {
        $path = $path === '' ? '/' : $path;

        if (array_key_exists($path, self::PAGES)) {
            return true;
        }

        if (preg_match('#^/blog/([\w\-]+)$#', $path, $found) === 1) {
            return BlogPost::published()->where('slug', $found[1])->exists();
        }

        return false;
    }

    private static function build(string $locale): string
    {
        return implode("\n\n", array_filter([
            self::identity(),
            self::rules($locale),
            self::pricing(),
            self::facts(),
            self::knowledge($locale),
            self::pages(),
        ]));
    }

    private static function identity(): string
    {
        return 'You are the assistant on the Auto Resellers Hub website — a friendly, knowledgeable '
            .'guide, not a salesperson. Auto Resellers Hub sells WhatsApp automation to people who '
            .'resell social media marketing services: bots that take orders and handle support on '
            ."their WhatsApp number, connected to the SMM panel they already buy from.\n"
            .'The people who talk to you are mostly newcomers who do not yet know what an SMM panel, '
            .'an API key, a webhook or the WhatsApp Cloud API is, and who have not signed up. Your job '
            .'is to make all of it understandable: answer what they ask, explain the terms in plain '
            .'words, walk them through what setup involves, and point them at the right page. Be warm, '
            .'direct and concrete. Never push.';
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
            '2. Answer from FACTS, KNOWLEDGE and PRICING. You may also explain the general background a newcomer needs in order to understand us, in plain words: what an SMM panel is and why people resell from one, what an API key is, what the WhatsApp Cloud API and a Meta Business account are, how a webhook works, what a wallet balance or a referral is. Do not give opinions on, or make claims about, other companies\' products or prices. Anything unrelated to us and to that background — politics, medical or legal advice, writing code, homework — decline in one friendly sentence and steer back to what you can help with.',
            '3. Never claim a feature exists unless FACTS or KNOWLEDGE says so. If asked whether we do something not described, say you are not sure and offer to check with the team. Say plainly what is not possible yet; it is better than a promise nobody can keep.',
            '4. You cannot create accounts, take payments, look up an order, or access anyone\'s data. For anything like that, point them at the right page or at the team.',
            '5. Never ask for a password, a card number, an API key or a login. There is nothing a visitor should type into this chat that is secret. If they offer one, tell them not to share it here.',
            '6. Format for a small chat window: usually under 110 words, short paragraphs, and when it helps use **bold** for the key terms and lines starting with "- " for steps or options. No headings, no tables, no emojis unless the visitor uses them. Never open with "Great question".',
            "7. {$hint} Reply in the language the visitor writes in, whatever it is — match them turn by turn, and if they switch, you switch. Keep product names (Order Bot, Support Bot, WhatsApp, API) as they are.",
            '8. End with a call to action only when a page in PAGES is genuinely the next step. Put each on its own final line in exactly this form: [[CTA:Short label|/path]]. The label is at most four words, in the visitor\'s language. The path must be copied exactly from PAGES. Never more than two, and none for a purely informational question. Someone who wants to see it work belongs at /try; someone ready to begin belongs at /register.',
            '9. If you do not know something about the product, say so and offer to put them in touch with the team. Do not guess.',
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

    /**
     * What the product does, written once and kept honest.
     *
     * The lists that change — languages, gateways, setup steps — are read from
     * the code that uses them. The rest is how the bots behave, and it says
     * what they do rather than what they might: the support bot passes a
     * cancellation to the reseller instead of cancelling, and a prompt that
     * implied otherwise would be promising customers something the bot cannot
     * do.
     */
    private static function facts(): string
    {
        $botLanguages = implode(', ', array_values(BotLang::NAMES));

        $gateways = collect(Gateway::all())
            ->filter(fn (array $gateway) => ($gateway['ready'] ?? false) === true)
            ->map(fn (array $gateway, string $code) => Gateway::label($code))
            ->values()
            ->implode(', ');

        $steps = collect(OnboardingStep::ordered())
            ->map(fn (OnboardingStep $step, int $index) => ($index + 1).'. '.$step->title().' — '.$step->description()
                .($step->isRequired() ? '' : ' (optional)'))
            ->implode("\n");

        return implode("\n", array_filter([
            'FACTS — what the product is and does (this is true; use it freely):',
            '',
            'How it fits together: a reseller already buys social media services (followers, likes, views) from an SMM panel and sells them on to their own customers. Auto Resellers Hub puts that selling on WhatsApp. The reseller\'s customers message the reseller\'s own WhatsApp number; a bot takes the order, charges the customer\'s wallet, and sends the order to the reseller\'s panel automatically. It runs on Meta\'s official WhatsApp Cloud API, so the number is not at risk the way tools that scan a QR code are. Customers pay the reseller through the reseller\'s own payment gateways — the money never passes through us, and we take no cut of orders.',
            '',
            'Order Bot (sells). A customer says hi and gets a menu: New order (pick a platform, then a category, a service, a quantity, send the link, confirm), Add funds (top up the wallet through the reseller\'s gateway), My profile (balance, total spent, their referral code), Refer a friend (code, how many they brought, what they earned), Track order (their recent orders), Support (answered by AI where the reseller has the AI add-on), and Settings (choose a language). Optional links to the reseller\'s group and website. If the wallet is short, the bot offers a top-up and places the order once it clears. The bot speaks '.$botLanguages.', chosen per customer rather than per shop.',
            '',
            'Support Bot (handles what comes after the sale). A numbered Quick Menu: 1 Refill (checked against the reseller\'s own guarantee rules, then sent to the panel if it is owed), 2 Speed up (the request is passed to the reseller; off by default), 3 Cancel (the request is passed to the reseller with the order ID), 4 Partial or fake completion (reported to the reseller for review), 5 Talk to a human (the bot steps back and the reseller replies from their inbox on the same WhatsApp number), 6 Order status (read live from the panel), 7 Top-up problem (collects the amount, reference and time), 8 AI FAQ (answers questions about the reseller\'s services, where they have the AI add-on). Each of refill, status, cancel and speed-up can be switched off. The support menu is in English for now. Cancel, speed-up and partial reports notify the reseller — they do not act on the panel by themselves.',
            '',
            'The reseller\'s dashboard: their service catalogue and prices (margins, bulk price rules, price history, services paused automatically when a panel drops them), orders (filters, bulk actions, export), customers (a CRM with wallets, tags, blocking and broadcasts to people who wrote in the last 24 hours), several panels at once, alerts when a panel stops answering or runs low on balance, a shared inbox for human handovers, API keys with logs (the standard SMM API v2: services, add, status, refill, balance), and billing.',
            '',
            'Getting started, in order:',
            $steps,
            'A reseller needs: an SMM panel account (the panel\'s URL and the API key from its Account / API page — an ordinary account key is enough), and a WhatsApp number. For the number they can rent one from us — no Meta account, live the same day, on the same official API — or connect their own Meta number. Everything starts in sandbox: the reseller\'s own phone, registered as a test number, gets answers before anything is paid for. Anyone else gets answers once the service is paid for and live.',
            '',
            'Try before signing up: /try is a practice chat on a phone screen running the real bots on a sample shop. No signup; nothing is charged, sent to a panel or saved. Signed-up resellers get the same screen as the first step of setup, and later with their own services and prices.',
            '',
            $gateways === '' ? null : 'Payment methods a reseller can offer their customers: '.$gateways.'. Mobile money and crypto are both supported; the reseller connects their own accounts.',
            'The bots currently run on WhatsApp.',
            '',
            'Plain-words glossary you can draw on: an SMM panel is a wholesale marketplace of social media services with an API; an API key is a password-like code that lets software (our bot) place orders on that panel for you; the WhatsApp Cloud API is Meta\'s official way for businesses to run WhatsApp automatically; a webhook is how Meta tells us a message arrived; a wallet is a balance each customer tops up and orders draw from; sandbox means try everything with your own phone before it is live for customers.',
        ]));
    }

    private static function knowledge(string $locale): string
    {
        $entries = AssistantKnowledge::live();

        if ($entries->isEmpty()) {
            return 'KNOWLEDGE: no written answers yet. Rely on FACTS and PRICING above.';
        }

        $lines = $entries->map(function (AssistantKnowledge $entry) use ($locale) {
            $block = "Q: {$entry->question}\nA: {$entry->answerIn($locale)}";

            $cta = $entry->cta();

            if ($cta !== null) {
                $block .= "\nSuggested CTA: [[CTA:{$cta['label']}|{$cta['url']}]]";
            }

            return $block;
        })->implode("\n\n");

        return "KNOWLEDGE — answers the team wrote, which take precedence over anything above if they differ:\n\n".$lines;
    }

    private static function pages(): string
    {
        $lines = collect(self::PAGES)
            ->map(fn (string $description, string $path) => "- {$path} — {$description}")
            ->implode("\n");

        $guides = BlogPost::published()
            ->orderByDesc('published_at')
            ->limit(self::BLOG_POSTS)
            ->get(['slug', 'title'])
            ->map(fn (BlogPost $post) => "- /blog/{$post->slug} — Guide: {$post->title}")
            ->implode("\n");

        return "PAGES you may link to in a CTA (these paths exist; no others do):\n".$lines
            .($guides === '' ? '' : "\n".$guides);
    }

    /** Which language to answer in, when it is known rather than guessed. */
    private static function languageContext(string $language): string
    {
        $name = VisitorLanguage::englishName($language);

        return "LANGUAGE: the visitor is writing in {$name}. Reply entirely in {$name}, "
            .'including the label on any call to action. Product names stay as they are.';
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
