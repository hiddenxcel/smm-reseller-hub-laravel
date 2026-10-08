<?php

namespace App\Services\Bots\Order;

use App\Actions\Orders\PlaceOrder;
use App\Jobs\SubmitOrderToPanel;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Ai\AiAnswers;
use App\Services\Bots\BotHandler;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotMessenger;
use App\Services\Bots\BotSimulation;
use App\Services\Bots\BotSettings;
use App\Services\Bots\StaffAlerts;
use App\Services\Customers\CustomerReferrals;
use App\Services\Payments\ExchangeRates;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\StartTopup;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The selling bot: a customer messages the reseller's number and walks out
 * with an order placed on the reseller's panel, paid from a wallet balance.
 *
 *   "hi" -> main menu
 *      New order -> platform -> category -> service -> quantity -> link
 *                -> confirm -> [enough balance?] place it, or top up first
 *
 * State lives in bot_conversations, so each message is handled from scratch —
 * there is no in-memory session between messages.
 *
 * Everything the customer sees goes through BotLang in their own language.
 */
class OrderBotHandler implements BotHandler
{
    private const BOT = 'order';

    /** Offered as quick picks, filtered to what the service actually allows. */
    private const QUANTITY_PRESETS = [100, 500, 1000, 2000, 5000, 10000, 50000, 100000];

    /** WhatsApp will not render more rows than this in one list. */
    private const MAX_LIST_ROWS = 10;

    /** Anything here drops the customer back to the menu, wherever they were. */
    private const RESET_WORDS = ['hi', 'hello', 'menu', 'start', 'habari', 'mambo', '#'];

    /**
     * Question-and-answer pairs carried into the next AI question. Enough for
     * a follow-up to make sense, few enough that a long conversation does not
     * re-bill the reseller for its opening on every turn.
     */
    private const AI_HISTORY_TURNS = 4;

    private int $tenantId;

    private array $shop;

    private string $currency;

    /** What this customer sees prices in. The shop's own currency unless they chose another. */
    private string $displayCurrency;

    private string $locale = BotLang::DEFAULT;

    private GatewayFactory $gateways;

    private StartTopup $topups;

    public function __construct(
        private Tenant $tenant,
        private BotMessenger $messenger,
    ) {
        $this->tenantId = (int) $tenant->id;
        $this->shop = Arr::get(BotSettings::for($this->tenantId, self::BOT), 'shop', []);
        $this->currency = $this->shop['currency'] ?? 'USD';
        $this->displayCurrency = $this->currency;

        // Resolved rather than injected: BotHandlerFactory constructs handlers
        // with (tenant, messenger) by contract, and both bots depend on that
        // signature. Tests still swap these through the container.
        $this->gateways = app(GatewayFactory::class);
        $this->topups = app(StartTopup::class);
    }

    public function handle(string $from, string $text): void
    {
        $text = trim($text);
        $customer = $this->customer($from);
        $this->locale = BotLang::resolve($customer, $this->shop['lang'] ?? null);
        $this->displayCurrency = $this->displayCurrencyFor($customer);

        $conversation = BotConversation::current($this->tenantId, $from, self::BOT);
        $state = $this->stateOf($conversation);
        $context = $conversation?->context ?? [];

        if ($state === null || $this->isResetWord($text)) {
            $this->showMainMenu($from, $customer);

            return;
        }

        match ($state) {
            OrderState::MainMenu => $this->onMenuChoice($from, $text, $customer),
            OrderState::SettingsMenu => $this->onSettingsChosen($from, $text, $customer),
            OrderState::SelectLanguage => $this->onLanguageChosen($from, $text, $customer),
            OrderState::SelectCurrency => $this->onCurrencyChosen($from, $text, $context, $customer),
            OrderState::SelectPlatform => $this->onPlatformChosen($from, $text, $customer),
            OrderState::SelectCategory => $this->onCategoryChosen($from, $text, $context, $customer),
            OrderState::SelectService => $this->onServiceChosen($from, $text, $context),
            OrderState::SelectQuantity => $this->onQuantityChosen($from, $text, $context, $customer),
            OrderState::SendLink => $this->onLinkGiven($from, $text, $context),
            OrderState::Confirm => $this->onConfirmed($from, $text, $context, $customer),
            OrderState::ReferralCode => $this->onReferralCodeGiven($from, $text, $customer),
            OrderState::AwaitingPayment => $this->say($from, 'awaiting_payment'),

            OrderState::TopupDecision => $this->onTopupDecision($from, $text, $context, $customer),
            OrderState::TopupAmount => $this->onTopupAmount($from, $text, $customer),
            OrderState::SelectGateway => $this->onGatewayChosen($from, $text, $context, $customer),
            OrderState::TopupPhone => $this->onTopupPhone($from, $text, $context, $customer),

            OrderState::AiChat => $this->onAiQuestion($from, $text, $context, $customer),

            default => $this->showMainMenu($from, $customer),
        };
    }

    // ---- main menu -------------------------------------------------------

    private function showMainMenu(string $from, BotCustomer $customer): void
    {
        $name = $customer->firstName() ?? $this->t('default_customer_name');

        $rows = [
            $this->menuRow('new_order'),
            $this->menuRow('topup'),
            $this->menuRow('profile'),
            $this->menuRow('referral'),
            $this->menuRow('track'),
            $this->menuRow('support'),
            $this->menuRow('settings'),
        ];

        // Optional links only appear once the reseller has set them.
        if (filled($this->shop['group_url'] ?? null)) {
            $rows[] = $this->menuRow('group');
        }

        if (filled($this->shop['website_url'] ?? null)) {
            $rows[] = $this->menuRow('website');
        }

        $this->moveTo($from, OrderState::MainMenu);

        $this->messenger->sendList(
            $from,
            $this->t('menu_welcome', [
                'name_upper' => mb_strtoupper($name),
                'name' => $name,
                'business' => $this->tenant->business_name,
            ]),
            $this->t('btn_open_menu'),
            $this->t('menu_header'),
            $rows,
            'WELCOME',
        );
    }

    private function menuRow(string $key): array
    {
        return [
            'id' => "main:{$key}",
            'title' => $this->t("menu_{$key}_title"),
            'description' => $this->t("menu_{$key}_desc"),
        ];
    }

    private function onMenuChoice(string $from, string $text, BotCustomer $customer): void
    {
        $choice = str_starts_with($text, 'main:') ? substr($text, 5) : '';

        match ($choice) {
            'new_order' => $this->startOrder($from, $customer),
            'profile' => $this->showProfile($from, $customer),
            'referral' => $this->showReferral($from, $customer),
            'track' => $this->showRecentOrders($from),
            'settings' => $this->showSettings($from),
            'group' => $this->sayAndFinish($from, 'group_info', ['url' => $this->shop['group_url'] ?? '']),
            'website' => $this->sayAndFinish($from, 'website_info', ['url' => $this->shop['website_url'] ?? '']),
            'topup' => $this->askTopupAmount($from),
            'support' => $this->startAiChat($from),

            default => $this->say($from, 'not_understood_menu'),
        };
    }

    // ---- AI support ------------------------------------------------------

    /**
     * The support option, answered by AI when the reseller has the add-on.
     *
     * Without it the customer is told to reach the shop directly rather than
     * being left on an option that does nothing — which is what this was
     * before the AI port.
     */
    private function startAiChat(string $from): void
    {
        if (! app(AiAnswers::class)->isAvailable($this->tenantId)) {
            $this->sayAndFinish($from, 'support_unavailable');

            return;
        }

        $this->moveTo($from, OrderState::AiChat, ['history' => []]);

        $this->say($from, 'ai_chat_open');
    }

    /**
     * A question for the assistant.
     *
     * Reset words are caught in handle() before this runs, so *hi* or *menu*
     * always gets the customer out — they are never held in a conversation
     * with the AI.
     */
    private function onAiQuestion(
        string $from,
        string $text,
        array $context,
        BotCustomer $customer,
    ): void {
        if ($text === '') {
            $this->say($from, 'ai_chat_empty');

            return;
        }

        $history = is_array($context['history'] ?? null) ? $context['history'] : [];

        $answer = app(AiAnswers::class)->answer(
            tenant: $this->tenant,
            question: $text,
            shop: $this->shop,
            history: $history,
        );

        // DeepSeek was unreachable or refused the key, or the add-on lapsed
        // mid-conversation. The customer is returned to the menu, which is
        // somewhere they can still buy from.
        if ($answer === null) {
            $this->say($from, 'ai_chat_failed');
            $this->showMainMenu($from, $customer);

            return;
        }

        $this->messenger->sendText($from, $answer, 'AI_CHAT_ANSWER');

        $history[] = ['role' => 'user', 'content' => $text];
        $history[] = ['role' => 'assistant', 'content' => $answer];

        // Capped: the whole history is re-sent with every question, so an
        // unbounded one bills the reseller for the same opening exchange over
        // and over.
        $this->moveTo($from, OrderState::AiChat, [
            'history' => array_slice($history, -self::AI_HISTORY_TURNS * 2),
        ]);
    }

    // ---- settings --------------------------------------------------------

    /** Language, and — when there is more than one to choose from — currency. */
    private function showSettings(string $from): void
    {
        $currencies = ExchangeRates::catalogue();

        // One currency means nothing to choose; go straight to language.
        if (count($currencies) < 2) {
            $this->askLanguage($from);

            return;
        }

        $this->moveTo($from, OrderState::SettingsMenu);

        $this->messenger->sendButtons($from, $this->t('settings_menu'), [
            ['id' => 'set:language', 'title' => $this->t('btn_language')],
            ['id' => 'set:currency', 'title' => $this->t('btn_currency')],
        ]);
    }

    private function onSettingsChosen(string $from, string $text, BotCustomer $customer): void
    {
        match ($text) {
            'set:language' => $this->askLanguage($from),
            'set:currency' => $this->askCurrency($from, $customer),
            default => $this->say($from, 'settings_press_option'),
        };
    }

    /** A list holds ten rows: nine currencies, and one to move on to the next nine. */
    private const CURRENCIES_PER_PAGE = 9;

    /**
     * One page of the currencies a customer can see prices in. The shop's own
     * comes first. There are far more than fit in one list, so the last row
     * leads to the next page (and back to the first from the last), and any
     * currency can also simply be typed as its three-letter code.
     */
    private function askCurrency(string $from, BotCustomer $customer, int $page = 0): void
    {
        $choices = $this->currencyChoices();
        $pages = max(1, (int) ceil(count($choices) / self::CURRENCIES_PER_PAGE));
        $page = max(0, min($page, $pages - 1));

        // Everything fits in one list: no paging.
        $slice = count($choices) <= 10
            ? $choices
            : array_slice($choices, $page * self::CURRENCIES_PER_PAGE, self::CURRENCIES_PER_PAGE);

        $rows = [];

        foreach ($slice as $entry) {
            $rows[] = [
                'id' => 'cur:'.$entry['code'],
                'title' => $entry['code'].' — '.$entry['name'],
                'description' => $entry['code'] === $this->currency ? $this->t('currency_shop_row') : '',
            ];
        }

        if ($pages > 1) {
            $rows[] = $page < $pages - 1
                ? [
                    'id' => 'cur_more',
                    'title' => $this->t('currency_more_title'),
                    'description' => $this->t('currency_more_desc', ['page' => $page + 2, 'pages' => $pages]),
                ]
                : [
                    'id' => 'cur_first',
                    'title' => $this->t('currency_first_title'),
                    'description' => $this->t('currency_first_desc'),
                ];
        }

        $this->moveTo($from, OrderState::SelectCurrency, ['cur_page' => $page]);

        $this->messenger->sendList(
            $from,
            $this->t('settings_choose_currency', ['shop' => $this->currency]).($pages > 1
                ? "\n\n".$this->t('currency_page', ['page' => $page + 1, 'pages' => $pages])
                : ''),
            $this->t('btn_currency'),
            $this->t('btn_currency'),
            $rows,
        );
    }

    /** @return array<int, array{code: string, name: string, perUsd: float}> */
    private function currencyChoices(): array
    {
        $all = ExchangeRates::catalogue();

        usort($all, fn (array $a, array $b) => ($b['code'] === $this->currency) <=> ($a['code'] === $this->currency));

        return $all;
    }

    private function onCurrencyChosen(string $from, string $text, array $context, BotCustomer $customer): void
    {
        $page = (int) ($context['cur_page'] ?? 0);

        if ($text === 'cur_more') {
            $this->askCurrency($from, $customer, $page + 1);

            return;
        }

        if ($text === 'cur_first') {
            $this->askCurrency($from, $customer, 0);

            return;
        }

        $code = strtoupper(trim(str_starts_with($text, 'cur:') ? substr($text, 4) : $text));

        // A list row, or a code typed in — for any currency, on a page or not.
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || ! ExchangeRates::supports($code)) {
            $this->say($from, 'settings_press_currency');

            return;
        }

        // The shop's own currency is "no preference", so a later change of the
        // shop's currency is followed rather than left behind.
        $customer->update(['currency' => $code === $this->currency ? null : $code]);
        $this->displayCurrency = $code;

        $this->say($from, $code === $this->currency ? 'currency_reset' : 'currency_changed', [
            'currency' => $code,
            'shop' => $this->currency,
        ]);

        $this->showMainMenu($from, $customer);
    }

    private function displayCurrencyFor(BotCustomer $customer): string
    {
        $chosen = strtoupper((string) $customer->currency);

        return $chosen !== '' && ExchangeRates::supports($chosen) ? $chosen : $this->currency;
    }

    private function askLanguage(string $from): void
    {
        $rows = array_map(
            fn (string $code) => [
                'id' => "lang:{$code}",
                'title' => $this->t("lang_name_{$code}"),
                'description' => '',
            ],
            BotLang::SUPPORTED,
        );

        $this->moveTo($from, OrderState::SelectLanguage);

        $this->messenger->sendList(
            $from,
            $this->t('settings_choose_language'),
            $this->t('menu_settings_title'),
            $this->t('menu_header'),
            $rows,
        );
    }

    private function onLanguageChosen(string $from, string $text, BotCustomer $customer): void
    {
        if (! str_starts_with($text, 'lang:')) {
            $this->say($from, 'settings_press_language');

            return;
        }

        $chosen = BotLang::normalize(substr($text, 5));
        $customer->update(['lang' => $chosen]);

        // Confirm in the language they just picked, then reopen the menu in it.
        $this->locale = $chosen;
        $this->say($from, 'language_changed');
        $this->showMainMenu($from, $customer);
    }

    // ---- informational replies -------------------------------------------

    private function showProfile(string $from, BotCustomer $customer): void
    {
        $this->sayAndFinish($from, 'profile', [
            'balance' => $this->money($customer->balance),
            'spent' => $this->money($customer->total_spent),
            'code' => $customer->referral_code ?: '—',
        ]);
    }

    private function showReferral(string $from, BotCustomer $customer): void
    {
        $this->sayAndFinish($from, 'referral_info', [
            'code' => $customer->referral_code ?: '—',
            'count' => CustomerReferrals::countFor($customer),
            'earnings' => $this->money($customer->referral_earnings),
        ]);

        // Someone who has not yet said who invited them is asked once, right
        // after seeing their own code — the moment referrals are on their mind.
        // Anyone already linked is left alone.
        if ($customer->referred_by === null) {
            $this->moveTo($from, OrderState::ReferralCode);
            $this->say($from, 'referral_ask_code');
        }
    }

    /**
     * The code of whoever invited them.
     *
     * A wrong code keeps them here to try again rather than dropping them back
     * to the menu — a mistyped character should not cost them the bonus.
     */
    private function onReferralCodeGiven(string $from, string $text, BotCustomer $customer): void
    {
        $text = trim($text);

        if ($text === '' || mb_strtolower($text) === 'skip') {
            $this->sayAndFinish($from, 'referral_skipped');

            return;
        }

        if ($customer->referred_by !== null) {
            $this->sayAndFinish($from, 'referral_already_linked');

            return;
        }

        if (! CustomerReferrals::claim($customer, $text)) {
            $this->say($from, 'referral_unknown_code');

            return;
        }

        $this->sayAndFinish($from, 'referral_claimed', [
            'code' => mb_strtoupper($text),
        ]);
    }

    private function showRecentOrders(string $from): void
    {
        $orders = BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('customer_phone', $from)
            ->latest('id')
            ->limit(5)
            ->get();

        if ($orders->isEmpty()) {
            $this->sayAndFinish($from, 'track_none');

            return;
        }

        $body = $this->t('track_header');

        foreach ($orders as $order) {
            $body .= $this->t('track_line', [
                'number' => $order->provider_order_id ?: $order->id,
                'service' => $order->service_name ?: '—',
                'status' => $order->customerStatus(),
                'amount' => $this->money($order->amount ?? '0'),
            ]);
        }

        $this->sayAndFinish($from, null, [], $body.$this->t('track_footer'));
    }

    // ---- ordering: platform, category, service ---------------------------

    private function startOrder(string $from, BotCustomer $customer): void
    {
        // Paused services still count towards a platform being offered: the
        // customer should see "Instagram" and then find one option greyed out,
        // rather than the whole platform vanishing because one panel is down.
        $platforms = BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('status', [BotService::ACTIVE, BotService::PAUSED])
            ->distinct()
            ->orderBy('platform')
            ->pluck('platform');

        if ($platforms->isEmpty()) {
            $this->sayAndFinish($from, 'store_not_ready');

            return;
        }

        $rows = $platforms->take(self::MAX_LIST_ROWS)
            ->map(fn (string $platform) => [
                'id' => "plat_{$platform}",
                'title' => $platform,
                'description' => '',
            ])
            ->values()
            ->all();

        $this->moveTo($from, OrderState::SelectPlatform);

        $this->messenger->sendList(
            $from,
            $this->t('choose_platform', ['name' => $customer->firstName() ?? $this->t('default_customer_name')]),
            $this->t('btn_platforms'),
            $this->t('platforms_header'),
            $rows,
            'WELCOME',
        );
    }

    private function onPlatformChosen(string $from, string $text, BotCustomer $customer): void
    {
        $platform = str_starts_with($text, 'plat_') ? substr($text, 5) : $text;

        $services = $this->servicesFor($platform);

        if ($services->isEmpty()) {
            $this->say($from, 'pick_platform_again');

            return;
        }

        $categories = $services->pluck('category')->filter()->unique()->values();
        $hasUncategorised = $services->contains(fn (BotService $s) => blank($s->category));
        $buckets = $categories->count() + ($hasUncategorised ? 1 : 0);

        // A category step whenever the reseller has typed their services, as long as
        // it fits in one list.
        if ($categories->isNotEmpty() && $buckets <= self::MAX_LIST_ROWS) {
            $this->askCategory($from, $platform, $categories, $hasUncategorised);

            return;
        }

        $this->showServices($from, $platform, null, $services, $customer);
    }

    private function askCategory(string $from, string $platform, $categories, bool $hasUncategorised): void
    {
        $rows = $categories
            ->map(fn (string $category) => [
                'id' => 'cat_'.rawurlencode($category),
                'title' => mb_substr($category, 0, 24),
                'description' => '',
            ])
            ->values()
            ->all();

        if ($hasUncategorised) {
            $rows[] = ['id' => 'cat_', 'title' => $this->t('category_other'), 'description' => ''];
        }

        $this->moveTo($from, OrderState::SelectCategory, ['platform' => $platform]);

        $this->messenger->sendList(
            $from,
            $this->t('choose_category', ['platform' => $platform]),
            $this->t('categories_header'),
            $this->t('categories_header'),
            $rows,
            'SELECT_CATEGORY',
        );
    }

    private function onCategoryChosen(string $from, string $text, array $context, BotCustomer $customer): void
    {
        $platform = $context['platform'] ?? '';
        $category = str_starts_with($text, 'cat_') ? rawurldecode(substr($text, 4)) : $text;

        $services = $this->servicesFor($platform)->filter(
            fn (BotService $service) => $category === ''
                ? blank($service->category)
                : $service->category === $category
        );

        if ($services->isEmpty()) {
            $this->say($from, 'pick_category_again');

            return;
        }

        $this->showServices($from, $platform, $category ?: null, $services, $customer);
    }

    private function showServices(string $from, string $platform, ?string $category, $services, BotCustomer $customer): void
    {
        $rows = [];
        $catalogue = [];

        foreach ($services->take(self::MAX_LIST_ROWS) as $service) {
            $isPaused = $service->status === BotService::PAUSED;
            // The price per 1,000 as the reseller set it. (This used to divide by
            // 1,000 first and then label the result "/ 1k", so a service at 4.00
            // per thousand was listed as "0.00 / 1k".)
            $price = $this->money($service->my_price).' '.$this->t('per_1k');

            $rows[] = [
                'id' => "svc_{$service->id}",
                'title' => mb_substr($service->name, 0, 24),
                // WhatsApp list rows cannot be disabled, so a paused service is
                // labelled instead — better than letting a customer pick it and
                // only then be told no.
                'description' => $isPaused
                    ? mb_substr($this->t('service_paused_label').' · '.$price, 0, 72)
                    : $price,
            ];

            // The chosen service is snapshotted into the conversation so a
            // price change mid-flow cannot alter what the customer agreed to.
            $catalogue[(string) $service->id] = [
                'id' => (int) $service->id,
                'name' => $service->name,
                'my_price' => (string) $service->my_price,
                'min' => (int) $service->min_quantity,
                'max' => (int) $service->max_quantity,
                'unit' => $service->unit_label,
                'panel_id' => $service->panel_id,
                'provider_service_id' => $service->provider_service_id,
                // Snapshotted for the same reason as the price: what this
                // order cost is what the panel charged on the day, and a cost
                // that moves next week must not rewrite last week's margin.
                'cost_price' => $service->cost_price === null ? null : (string) $service->cost_price,
                'paused' => $isPaused,
                'link_instructions' => $service->link_instructions,
                // What the card tells the customer before they choose a quantity.
                'description' => $service->description,
                'quality' => $service->quality,
                'speed' => $service->speed,
                'drop' => $service->drop_info,
                'refill' => $service->refill_info,
            ];
        }

        $this->moveTo($from, OrderState::SelectService, [
            'platform' => $platform,
            'category' => $category,
            'services' => $catalogue,
        ]);

        $heading = $category !== null ? "{$platform} · {$category}" : $platform;

        $this->messenger->sendList(
            $from,
            $this->t('choose_service', [
                'heading' => $heading,
                'heading_upper' => mb_strtoupper($heading),
                'name' => $customer->firstName() ?? $this->t('default_customer_name'),
            ]),
            $this->t('btn_services'),
            $this->t('services_header'),
            $rows,
            'SELECT_SERVICE',
        );
    }

    private function onServiceChosen(string $from, string $text, array $context): void
    {
        $serviceId = str_starts_with($text, 'svc_') ? substr($text, 4) : $text;
        $service = $context['services'][$serviceId] ?? null;

        if ($service === null) {
            $this->say($from, 'pick_service_again');

            return;
        }

        // Paused: listed so the customer knows it exists, but not orderable.
        // Checked here rather than only at the list, because the snapshot can
        // outlive the reseller pausing it mid-conversation.
        if (($service['paused'] ?? false) === true) {
            $this->say($from, 'service_paused');

            return;
        }

        $context['service'] = $service;
        $this->moveTo($from, OrderState::SelectQuantity, $context);
        $this->askQuantity($from, $service, $context);
    }

    // ---- ordering: quantity, link, confirm -------------------------------

    /**
     * Words that mean "take me back a step". A word, a number and an arrow, so
     * whichever a customer reaches for works — and the arrow is also a row at
     * the end of the quantity list.
     */
    private const BACK_WORDS = ['back', '0', '⬅', '←', '<', '‹', 'rudi', 'nyuma', 'retour', 'geri', 'वापस', 'رجوع'];

    private function isBack(string $text): bool
    {
        // The arrow emoji comes with an invisible variation selector attached.
        $text = mb_strtolower(trim(str_replace("\u{FE0F}", '', $text)));

        return $text === 'qty_back' || in_array($text, self::BACK_WORDS, true);
    }

    /**
     * Everything a customer should know about a service before choosing how
     * many: its full name, what it is, what it costs, and the promises the
     * reseller has made about it.
     *
     * Each line appears only when it has something to say. A service nobody has
     * described still gets its name, price, link and size — never an empty
     * "Quality:" with nothing after it.
     */
    private function serviceCard(array $service, array $context): string
    {
        $head = "🎯 *{$service['name']}*";

        if (filled($service['description'] ?? null)) {
            $head .= "\n\n".trim((string) $service['description']);
        }

        $facts = [
            $this->t('card_price', ['price' => $this->money($service['my_price'])]),
        ];

        if (filled($service['quality'] ?? null)) {
            $facts[] = $this->t('card_quality', ['value' => trim((string) $service['quality'])]);
        }

        if (filled($service['speed'] ?? null)) {
            $facts[] = $this->t('card_speed', ['value' => trim((string) $service['speed'])]);
        }

        // The reseller's own words, as written.
        if (filled($service['drop'] ?? null)) {
            $facts[] = $this->t('card_drop', ['value' => trim((string) $service['drop'])]);
        }

        if (filled($service['refill'] ?? null)) {
            $facts[] = $this->t('card_refill', ['value' => trim((string) $service['refill'])]);
        }

        $link = LinkGuide::for((string) ($context['platform'] ?? ''), $context['category'] ?? null, (string) ($service['unit'] ?? ''));
        $facts[] = $this->t('card_link', ['value' => $this->t($link['type'] === 'profile' ? 'card_link_profile' : 'card_link_post')]);

        $facts[] = $this->t('card_range', [
            'min' => number_format((int) $service['min']),
            'max' => number_format((int) $service['max']),
        ]);

        return $head."\n\n".implode("\n", $facts)."\n\n".$this->t('card_footer')."\n".$this->t('card_back_hint');
    }

    /** Back to the list of services the customer was choosing from. */
    private function backToServices(string $from, array $context, BotCustomer $customer): void
    {
        $platform = (string) ($context['platform'] ?? '');
        $category = $context['category'] ?? null;

        $services = $this->servicesFor($platform)->filter(
            fn (BotService $service) => $category === null
                ? blank($service->category)
                : $service->category === $category
        );

        if ($services->isEmpty()) {
            $this->startOrder($from, $customer);

            return;
        }

        $this->showServices($from, $platform, $category, $services, $customer);
    }

    private function askQuantity(string $from, array $service, array $context = []): void
    {
        // The card goes first, on its own, and the choice of quantity follows
        // as a second message: what the service is, then how many.
        $this->messenger->sendText($from, $this->serviceCard($service, $context));

        $rows = [];

        foreach (self::QUANTITY_PRESETS as $quantity) {
            if ($quantity < $service['min'] || $quantity > $service['max']) {
                continue;
            }

            $rows[] = [
                'id' => "qty_{$quantity}",
                'title' => $this->quantityLabel($quantity).' '.$service['unit'],
                'description' => $this->money($this->costOf($service, $quantity)),
            ];
        }

        $rows[] = [
            'id' => 'qty_custom',
            'title' => $this->t('qty_custom_title'),
            'description' => "{$service['min']} – {$service['max']}",
        ];

        $rows[] = [
            'id' => 'qty_back',
            'title' => $this->t('qty_back_title'),
            'description' => $this->t('qty_back_desc'),
        ];

        $this->messenger->sendList(
            $from,
            $this->t('how_many', ['service' => $service['name']]),
            $this->t('btn_packages'),
            $this->t('packages_header'),
            $rows,
            'SELECT_QTY',
        );
    }

    private function onQuantityChosen(string $from, string $text, array $context, BotCustomer $customer): void
    {
        $service = $context['service'];

        if ($this->isBack($text)) {
            $this->backToServices($from, $context, $customer);

            return;
        }

        $choice = str_starts_with($text, 'qty_') ? substr($text, 4) : $text;

        if ($choice === 'custom') {
            $this->say($from, 'qty_custom_prompt', [
                'min' => $service['min'],
                'max' => $service['max'],
            ]);

            return;
        }

        $quantity = (int) preg_replace('/\D/', '', $choice);

        if ($quantity < $service['min'] || $quantity > $service['max']) {
            $this->say($from, 'qty_out_of_range', [
                'min' => $service['min'],
                'max' => $service['max'],
            ]);

            return;
        }

        $context['quantity'] = $quantity;
        $this->moveTo($from, OrderState::SendLink, $context);
        $this->askForLink($from, $context, $customer);
    }

    /**
     * Ask for the link with the steps to copy it and, where there is one, a
     * picture of the taps. A reseller's own instructions on the service take
     * the place of the built-in steps but the picture still goes with them.
     */
    private function askForLink(string $from, array $context, BotCustomer $customer): void
    {
        $service = $context['service'];
        $platform = (string) ($context['platform'] ?? '');
        $guide = LinkGuide::for($platform, $context['category'] ?? null, (string) ($service['unit'] ?? ''));

        $steps = filled($service['link_instructions'] ?? null)
            ? (string) $service['link_instructions']
            : $this->t($guide['stepsKey'], ['platform' => $platform]);

        $message = $this->t('link_request', [
            'name' => $customer->firstName() ?? $this->t('default_customer_name'),
            'qty' => $this->quantityLabel((int) $context['quantity']).' '.$service['unit'],
            'image_note' => $guide['imageUrl'] !== null ? $this->t('link_see_image') : '',
            'steps' => $steps,
            'example' => $guide['example'],
        ]);

        if ($guide['imageUrl'] === null) {
            $this->messenger->sendText($from, $message, 'SEND_LINK');

            return;
        }

        $this->messenger->sendImage($from, $guide['imageUrl'], $message, 'SEND_LINK');
    }

    private function onLinkGiven(string $from, string $text, array $context): void
    {
        // Changed their mind about how many: back to the quantities.
        if ($this->isBack($text)) {
            unset($context['quantity']);
            $this->moveTo($from, OrderState::SelectQuantity, $context);
            $this->askQuantity($from, $context['service'], $context);

            return;
        }

        if (! filter_var($text, FILTER_VALIDATE_URL)) {
            $this->say($from, 'invalid_link');

            return;
        }

        $service = $context['service'];
        $quantity = (int) $context['quantity'];
        $amount = $this->costOf($service, $quantity);

        $context['link'] = $text;
        $context['amount'] = $amount;
        $this->moveTo($from, OrderState::Confirm, $context);

        $this->messenger->sendButtons(
            $from,
            $this->t('confirm_order', [
                'service' => $service['name'],
                'link' => $text,
                'qty' => number_format($quantity),
                'total' => $this->money($amount),
            ]),
            [
                ['id' => 'confirm_yes', 'title' => $this->t('btn_confirm')],
                ['id' => 'confirm_no', 'title' => $this->t('btn_cancel')],
            ],
        );
    }

    private function onConfirmed(string $from, string $text, array $context, BotCustomer $customer): void
    {
        $affirmative = ['confirm_yes', 'confirm', 'yes', 'ndio', 'ndiyo'];

        if (! in_array(mb_strtolower($text), $affirmative, true)) {
            $this->finish($from);
            $this->say($from, 'order_cancelled', [], 'GOODBYE');

            return;
        }

        $amount = (string) $context['amount'];

        if (bccomp((string) $customer->balance, $amount, 4) === -1) {
            $this->offerTopup($from, $context, $customer, $amount);

            return;
        }

        $this->placeOrder($from, $customer, $context);
    }

    private function placeOrder(string $from, BotCustomer $customer, array $context): void
    {
        $service = $context['service'];

        $result = app(PlaceOrder::class)->handle(
            customer: $customer,
            service: $service,
            link: $context['link'],
            quantity: (int) $context['quantity'],
            amount: (string) $context['amount'],
        );

        if (! $result->placed) {
            // Both cases mean the wallet did not cover it — the balance can
            // change between the check above and the debit.
            $this->say($from, 'wallet_charge_failed');
            $this->finish($from);

            return;
        }

        // Forwarding to the panel is a third-party call, so it happens off the
        // request — the customer is told their order is in either way.
        // A rehearsal never reaches the panel — see BotSimulation.
        if (! BotSimulation::active()) {
            SubmitOrderToPanel::dispatch($result->order->id);
        }

        $this->finish($from);

        $this->say($from, 'order_placed', [
            'number' => $result->order->id,
            'service' => $service['name'],
            'qty' => number_format((int) $context['quantity']),
            'amount' => $this->money($context['amount']),
            'balance' => $this->money($customer->fresh()->balance),
        ], 'CONFIRMED');

        $this->notifyStaff($from, $service['name'], (int) $context['quantity'], $result->order->id);
    }

    private function offerTopup(string $from, array $context, BotCustomer $customer, string $amount): void
    {
        $shortfall = bcsub($amount, (string) $customer->balance, 2);
        $context['shortfall'] = $shortfall;

        $this->moveTo($from, OrderState::TopupDecision, $context);

        $this->messenger->sendButtons(
            $from,
            $this->t('insufficient_balance', [
                'balance' => $this->shopMoney($customer->balance),
                'amount' => $this->shopMoney($amount),
                'shortfall' => $this->shopMoney($shortfall),
            ]),
            [
                ['id' => 'topup_yes', 'title' => $this->t('btn_topup_pay')],
                ['id' => 'topup_no', 'title' => $this->t('btn_cancel')],
            ],
        );
    }

    // ---- paying ----------------------------------------------------------

    /** "Add Funds" from the menu — a top-up with no order behind it. */
    private function askTopupAmount(string $from): void
    {
        $this->moveTo($from, OrderState::TopupAmount);

        $this->say($from, 'topup_prompt', [
            'cur' => $this->currency,
            'min' => $this->shopMoney((string) ($this->shop['min_topup'] ?? 1)),
        ]);
    }

    /**
     * "Top up & pay", or not.
     *
     * The shortfall is what gets collected, not the whole order — the customer
     * already has the rest sitting in their wallet.
     */
    private function onTopupDecision(
        string $from,
        string $text,
        array $context,
        BotCustomer $customer,
    ): void {
        // Interactive replies arrive as the button's id; a customer typing
        // instead is taken at their word either way.
        $affirmative = ['topup_yes', 'yes', 'ndio', 'ndiyo', 'pay'];

        if (! in_array(mb_strtolower(trim($text)), $affirmative, true)) {
            $this->sayAndFinish($from, 'payment_cancelled');

            return;
        }

        $amount = (string) ($context['shortfall'] ?? $context['amount'] ?? '0');

        $this->collect($from, $amount, $context, $customer);
    }

    /**
     * A standalone top-up from the menu: the customer names the amount.
     *
     * No order is attached, so nothing is placed when it clears — the money
     * simply lands in the wallet.
     */
    private function onTopupAmount(string $from, string $text, BotCustomer $customer): void
    {
        $min = (string) ($this->shop['min_topup'] ?? 1);
        $amount = trim(str_replace(',', '', $text));

        if (! is_numeric($amount) || bccomp($amount, $min, 2) === -1) {
            $this->say($from, 'topup_amount_invalid', [
                'min' => $this->shopMoney($min),
                'cur' => $this->currency,
            ]);

            return;
        }

        $this->collect($from, bcadd($amount, '0', 2), [], $customer);
    }

    /** Mobile money needs a number to push the prompt to. */
    private function onTopupPhone(
        string $from,
        string $text,
        array $context,
        BotCustomer $customer,
    ): void {
        $phone = preg_replace('/\D/', '', $text) ?? '';

        // Long enough to be a real number, short enough to be a phone. Anything
        // finer belongs to the gateway, which knows its own country's format.
        if (strlen($phone) < 9 || strlen($phone) > 15) {
            $this->say($from, 'pay_phone_invalid');

            return;
        }

        $this->start($from, (string) ($context['pay_amount'] ?? '0'), $context, $customer, $phone);
    }

    /**
     * Decide how the customer will pay, then collect it.
     *
     * A reseller who has connected several gateways has done so because their
     * customers want the choice — a Tanzanian shop taking both mobile money and
     * crypto serves two different customers — so the choice is offered rather
     * than silently resolved to the default.
     *
     * One gateway means no question worth asking, so it is used directly.
     */
    private function collect(
        string $from,
        string $amount,
        array $context,
        BotCustomer $customer,
    ): void {
        // A rehearsal pays instantly instead of opening a real gateway.
        if (BotSimulation::active()) {
            $this->simulateTopup($from, $amount, $context, $customer);

            return;
        }

        $usable = $this->gateways->usableFor($this->tenantId);

        if ($usable->isEmpty()) {
            $this->sayAndFinish($from, 'topup_no_gateway');

            return;
        }

        if ($usable->count() === 1) {
            $this->collectVia($from, $usable->first()->gateway, $amount, $context, $customer);

            return;
        }

        $this->askGateway($from, $usable, $amount, $context);
    }

    /**
     * The simulator's stand-in for a payment: the wallet is credited at once,
     * and the order that was waiting on it goes through.
     *
     * Said in English whatever the customer's language, like the staff alerts —
     * it is a note to the person trying the bot, not something a customer sees.
     */
    private function simulateTopup(string $from, string $amount, array $context, BotCustomer $customer): void
    {
        $customer->credit($amount);

        $this->messenger->sendText(
            $from,
            "🧪 *Demo payment*\nOn your live shop the customer pays through your own gateway here. For this rehearsal {$this->shopMoney($amount)} was added to the wallet.",
        );

        $hasOrder = filled($context['service'] ?? null) && filled($context['amount'] ?? null);

        if ($hasOrder) {
            $this->placeOrder($from, $customer->fresh(), $context);

            return;
        }

        $this->finish($from);
    }

    /**
     * The payment-method menu.
     *
     * Labels come from config/gateways.php rather than from translations: a
     * gateway's name is a brand and stays as it is in every language, and a
     * reseller adding one must not have to wait for five translations.
     *
     * @param  Collection<int, TenantPaymentGateway>  $usable
     */
    private function askGateway(string $from, $usable, string $amount, array $context): void
    {
        $rows = $usable
            // WhatsApp silently drops a list longer than this, which would hide
            // the gateways at the end rather than fail visibly.
            ->take(self::MAX_LIST_ROWS)
            ->map(fn ($row) => [
                'id' => "pay:{$row->gateway}",
                'title' => mb_substr(Gateway::label($row->gateway), 0, 24),
                'description' => $this->t('pay_method_'.(Gateway::needsPhone($row->gateway) ? 'mobile' : 'online')),
            ])
            ->all();

        $context['pay_amount'] = $amount;
        $this->moveTo($from, OrderState::SelectGateway, $context);

        $this->messenger->sendList(
            $from,
            $this->t('choose_payment_method', ['amount' => $this->shopMoney($amount)]),
            $this->t('btn_choose_payment'),
            $this->t('payment_header'),
            $rows,
            'PAY',
        );
    }

    /**
     * The customer picked a method.
     *
     * Re-checked against what is usable rather than trusted: a list reply can
     * arrive minutes later, by which time the reseller may have paused that
     * gateway.
     */
    private function onGatewayChosen(
        string $from,
        string $text,
        array $context,
        BotCustomer $customer,
    ): void {
        $chosen = str_starts_with($text, 'pay:') ? substr($text, 4) : '';
        $amount = (string) ($context['pay_amount'] ?? '0');

        if ($chosen === '' || $this->gateways->usableGateway($this->tenantId, $chosen) === null) {
            // Ask again rather than dropping them to the menu — they are one tap
            // from paying, and the amount is still in context.
            $usable = $this->gateways->usableFor($this->tenantId);

            if ($usable->isEmpty()) {
                $this->sayAndFinish($from, 'topup_no_gateway');

                return;
            }

            $this->say($from, 'pay_method_invalid');
            $this->askGateway($from, $usable, $amount, $context);

            return;
        }

        $this->collectVia($from, $chosen, $amount, $context, $customer);
    }

    /**
     * Ask for a phone first if this gateway pushes to one, otherwise go
     * straight to it.
     */
    private function collectVia(
        string $from,
        string $gateway,
        string $amount,
        array $context,
        BotCustomer $customer,
    ): void {
        // Carried so the phone step, which happens after the choice, still
        // knows which gateway the number is for.
        $context['pay_gateway'] = $gateway;

        if (Gateway::needsPhone($gateway)) {
            $context['pay_amount'] = $amount;
            $this->moveTo($from, OrderState::TopupPhone, $context);

            $this->say($from, 'ask_pay_phone', ['suggest' => $from]);

            return;
        }

        $this->start($from, $amount, $context, $customer, '');
    }

    /**
     * Hand off to the gateway and tell the customer what to do next.
     *
     * The conversation moves to AwaitingPayment either way, because that is
     * what CompleteTopup looks for when the webhook lands — a customer whose
     * state was cleared would have their order forgotten.
     */
    private function start(
        string $from,
        string $amount,
        array $context,
        BotCustomer $customer,
        string $phone,
    ): void {
        $result = $this->topups->handle(
            tenantId: $this->tenantId,
            customer: $customer,
            amount: $amount,
            currency: $this->currency,
            phone: $phone,
            // Blank when the shop has a single gateway and nothing was asked,
            // which lets StartTopup fall back to the reseller's default.
            gateway: (string) ($context['pay_gateway'] ?? ''),
            // The order that is waiting on this payment, kept with it.
            pendingOrder: $this->orderWaitingOnPayment($context),
        );

        if ($result->noGateway) {
            $this->sayAndFinish($from, 'topup_no_gateway');

            return;
        }

        if (! $result->started) {
            $this->sayAndFinish($from, 'payment_start_failed', [
                'message' => $result->message ?? '',
            ]);

            return;
        }

        // Whether an order is waiting decides the wording: one says the order
        // will be placed, the other only that the wallet will be credited.
        $hasOrder = filled($context['service'] ?? null) && filled($context['amount'] ?? null);

        $this->moveTo($from, OrderState::AwaitingPayment, $context);

        if ($result->isPush()) {
            $this->say($from, $hasOrder ? 'payment_push' : 'topup_only_push', [
                'amount' => $this->shopMoney($amount),
                'phone' => $phone,
            ]);

            return;
        }

        $this->say($from, $hasOrder ? 'payment_link' : 'topup_only_link', [
            'amount' => $this->shopMoney($amount),
            'url' => (string) $result->redirectUrl,
        ]);
    }

    /**
     * The order a top-up is for, if the customer was placing one — enough to
     * place it later without the conversation.
     *
     * @return array{service: array, link: string, quantity: int, amount: string}|null
     */
    private function orderWaitingOnPayment(array $context): ?array
    {
        if (blank($context['service'] ?? null) || blank($context['amount'] ?? null) || blank($context['link'] ?? null)) {
            return null;
        }

        return [
            'service' => $context['service'],
            'link' => (string) $context['link'],
            'quantity' => (int) $context['quantity'],
            'amount' => (string) $context['amount'],
        ];
    }

    /**
     * Staff alerts are not translated: they go to the reseller's own team,
     * not to a customer, and the old platform sent them in English too.
     */
    private function notifyStaff(string $customerPhone, string $service, int $quantity, int $orderId): void
    {
        $summary = sprintf(
            "🛒 New order *#%d*\n%s × %s\nFrom: %s",
            $orderId,
            $service,
            number_format($quantity),
            $customerPhone,
        );

        app(StaffAlerts::class)->notify($this->tenant, self::BOT, $this->messenger, $summary);
    }

    // ---- helpers ---------------------------------------------------------

    private function customer(string $phone): BotCustomer
    {
        $customer = BotCustomer::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $this->tenantId, 'phone' => $phone],
            ['lang' => $this->shop['lang'] ?? BotLang::DEFAULT],
        );

        // Every customer needs a code of their own to share. Assigned here
        // rather than at creation so the customers who predate referrals get
        // one the next time they message.
        if (blank($customer->referral_code)) {
            CustomerReferrals::assignCode($customer);
        }

        return $customer;
    }

    /**
     * Hidden services are gone; paused ones are shown and then declined at the
     * point of ordering. That is the difference between the two: a reseller
     * pauses a service to say "not right now", not "pretend it never existed".
     */
    private function servicesFor(string $platform)
    {
        return BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('platform', $platform)
            ->whereIn('status', [BotService::ACTIVE, BotService::PAUSED])
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function stateOf(?BotConversation $conversation): ?OrderState
    {
        if ($conversation === null || $conversation->state === 'IDLE') {
            return null;
        }

        return OrderState::tryFrom($conversation->state);
    }

    private function isResetWord(string $text): bool
    {
        return in_array(mb_strtolower($text), self::RESET_WORDS, true);
    }

    private function moveTo(string $from, OrderState $state, array $context = []): void
    {
        BotConversation::put($this->tenantId, $from, self::BOT, $state->value, $context);
    }

    private function finish(string $from): void
    {
        BotConversation::clear($this->tenantId, $from, self::BOT);
    }

    private function t(string $key, array $replace = []): string
    {
        return BotLang::get($this->locale, $key, $replace);
    }

    private function say(string $from, ?string $key, array $replace = [], ?string $templateKey = null): void
    {
        $this->messenger->sendText($from, $key === null ? '' : $this->t($key, $replace), $templateKey);
    }

    private function sayAndFinish(string $from, ?string $key, array $replace = [], ?string $literal = null): void
    {
        $this->messenger->sendText($from, $literal ?? $this->t($key, $replace));
        $this->finish($from);
    }

    private function costOf(array $service, int $quantity): string
    {
        return bcdiv(bcmul((string) $service['my_price'], (string) $quantity, 4), '1000', 2);
    }

    private function quantityLabel(int $quantity): string
    {
        return $quantity >= 1000 ? number_format($quantity / 1000).'K' : (string) $quantity;
    }

    /**
     * An amount as this customer sees it: converted to the currency they chose,
     * with a leading "≈" because it is converted, or in the shop's own
     * currency when they chose nothing.
     *
     * For looking at — prices, totals, balances. Anything the customer is asked
     * to pay uses shopMoney(), so the figure on the payment is the figure that
     * is charged.
     */
    private function money(string|float $amount): string
    {
        if ($this->displayCurrency === $this->currency) {
            return $this->shopMoney($amount);
        }

        $converted = ExchangeRates::convert($amount, $this->currency, $this->displayCurrency);

        // No rate (it was removed since): the shop's own figure is better than none.
        if ($converted === null) {
            return $this->shopMoney($amount);
        }

        $whole = in_array($this->displayCurrency, ['TZS', 'UGX', 'XAF', 'NGN', 'KES'], true);

        return '≈ '.$this->displayCurrency.' '.number_format((float) $converted, $whole ? 0 : 2);
    }

    /** An amount in the shop's own currency, exactly — for everything that is paid. */
    private function shopMoney(string|float $amount): string
    {
        return $this->currency.' '.number_format((float) $amount, 2);
    }
}
