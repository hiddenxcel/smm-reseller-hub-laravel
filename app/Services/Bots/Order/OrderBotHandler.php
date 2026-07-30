<?php

namespace App\Services\Bots\Order;

use App\Actions\Orders\PlaceOrder;
use App\Jobs\SubmitOrderToPanel;
use App\Models\BotConversation;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\BotService;
use App\Models\Tenant;
use App\Services\Bots\BotHandler;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotMessenger;
use App\Services\Bots\BotSettings;
use Illuminate\Support\Arr;

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

    private int $tenantId;

    private array $shop;

    private string $currency;

    private string $locale = BotLang::DEFAULT;

    public function __construct(
        private Tenant $tenant,
        private BotMessenger $messenger,
    ) {
        $this->tenantId = (int) $tenant->id;
        $this->shop = Arr::get(BotSettings::for($this->tenantId, self::BOT), 'shop', []);
        $this->currency = $this->shop['currency'] ?? 'USD';
    }

    public function handle(string $from, string $text): void
    {
        $text = trim($text);
        $customer = $this->customer($from);
        $this->locale = BotLang::resolve($customer, $this->shop['lang'] ?? null);

        $conversation = BotConversation::current($this->tenantId, $from, self::BOT);
        $state = $this->stateOf($conversation);
        $context = $conversation?->context ?? [];

        if ($state === null || $this->isResetWord($text)) {
            $this->showMainMenu($from, $customer);

            return;
        }

        match ($state) {
            OrderState::MainMenu => $this->onMenuChoice($from, $text, $customer),
            OrderState::SelectLanguage => $this->onLanguageChosen($from, $text, $customer),
            OrderState::SelectPlatform => $this->onPlatformChosen($from, $text),
            OrderState::SelectCategory => $this->onCategoryChosen($from, $text, $context),
            OrderState::SelectService => $this->onServiceChosen($from, $text, $context),
            OrderState::SelectQuantity => $this->onQuantityChosen($from, $text, $context),
            OrderState::SendLink => $this->onLinkGiven($from, $text, $context),
            OrderState::Confirm => $this->onConfirmed($from, $text, $context, $customer),
            OrderState::AwaitingPayment => $this->say($from, 'awaiting_payment'),

            // Top-up and AI chat are wired in the next step; until then the
            // customer is returned to the menu rather than left stuck.
            default => $this->showMainMenu($from, $customer),
        };
    }

    // ---- main menu -------------------------------------------------------

    private function showMainMenu(string $from, BotCustomer $customer): void
    {
        $name = $customer->name ?: $this->t('default_customer_name');

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
            'new_order' => $this->startOrder($from),
            'profile' => $this->showProfile($from, $customer),
            'referral' => $this->showReferral($from, $customer),
            'track' => $this->showRecentOrders($from),
            'settings' => $this->askLanguage($from),
            'group' => $this->sayAndFinish($from, 'group_info', ['url' => $this->shop['group_url'] ?? '']),
            'website' => $this->sayAndFinish($from, 'website_info', ['url' => $this->shop['website_url'] ?? '']),

            // Top-up and support arrive with the payment flow.
            default => $this->say($from, 'not_understood_menu'),
        };
    }

    // ---- settings --------------------------------------------------------

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
        $referred = BotCustomer::withoutTenantScope()
            ->where('referred_by', $customer->id)
            ->count();

        $this->sayAndFinish($from, 'referral_info', [
            'code' => $customer->referral_code ?: '—',
            'count' => $referred,
            'earnings' => $this->money($customer->referral_earnings),
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
                'status' => $order->status ?: 'pending',
                'amount' => $this->money($order->amount ?? '0'),
            ]);
        }

        $this->sayAndFinish($from, null, [], $body.$this->t('track_footer'));
    }

    // ---- ordering: platform, category, service ---------------------------

    private function startOrder(string $from): void
    {
        $platforms = BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'active')
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
            $this->t('choose_platform'),
            $this->t('btn_platforms'),
            $this->t('platforms_header'),
            $rows,
            'WELCOME',
        );
    }

    private function onPlatformChosen(string $from, string $text): void
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

        // Only worth a category step when it actually narrows things down and
        // still fits in one list.
        if ($categories->count() > 1 && $buckets <= self::MAX_LIST_ROWS) {
            $this->askCategory($from, $platform, $categories, $hasUncategorised);

            return;
        }

        $this->showServices($from, $platform, null, $services);
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

    private function onCategoryChosen(string $from, string $text, array $context): void
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

        $this->showServices($from, $platform, $category ?: null, $services);
    }

    private function showServices(string $from, string $platform, ?string $category, $services): void
    {
        $rows = [];
        $catalogue = [];

        foreach ($services->take(self::MAX_LIST_ROWS) as $service) {
            $rows[] = [
                'id' => "svc_{$service->id}",
                'title' => mb_substr($service->name, 0, 24),
                'description' => $this->money($this->pricePerUnit($service)).' '.$this->t('per_1k'),
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
            ];
        }

        $this->moveTo($from, OrderState::SelectService, [
            'platform' => $platform,
            'category' => $category,
            'services' => $catalogue,
        ]);

        $heading = $category !== null ? "*{$platform} · {$category}*" : "*{$platform}*";

        $this->messenger->sendList(
            $from,
            $this->t('choose_service', ['heading' => $heading]),
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

        $context['service'] = $service;
        $this->moveTo($from, OrderState::SelectQuantity, $context);
        $this->askQuantity($from, $service);
    }

    // ---- ordering: quantity, link, confirm -------------------------------

    private function askQuantity(string $from, array $service): void
    {
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

        $this->messenger->sendList(
            $from,
            $this->t('how_many', ['service' => $service['name']]),
            $this->t('btn_packages'),
            $this->t('packages_header'),
            $rows,
            'SELECT_QTY',
        );
    }

    private function onQuantityChosen(string $from, string $text, array $context): void
    {
        $service = $context['service'];
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
        $this->say($from, 'send_link', ['service' => $service['name']], 'SEND_LINK');
    }

    private function onLinkGiven(string $from, string $text, array $context): void
    {
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
        SubmitOrderToPanel::dispatch($result->order->id);

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
                'balance' => $this->money($customer->balance),
                'amount' => $this->money($amount),
                'shortfall' => $this->money($shortfall),
            ]),
            [
                ['id' => 'topup_yes', 'title' => $this->t('btn_topup_pay')],
                ['id' => 'topup_no', 'title' => $this->t('btn_cancel')],
            ],
        );
    }

    /**
     * Staff alerts are not translated: they go to the reseller's own team,
     * not to a customer, and the old platform sent them in English too.
     */
    private function notifyStaff(string $customerPhone, string $service, int $quantity, int $orderId): void
    {
        $staff = Arr::get(BotSettings::for($this->tenantId, self::BOT), 'staff.numbers', []);

        $summary = sprintf(
            "🛒 New order *#%d*\n%s × %s\nFrom: %s",
            $orderId,
            $service,
            number_format($quantity),
            $customerPhone,
        );

        foreach ($staff as $number) {
            $this->messenger->sendText((string) $number, $summary);
        }
    }

    // ---- helpers ---------------------------------------------------------

    private function customer(string $phone): BotCustomer
    {
        return BotCustomer::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $this->tenantId, 'phone' => $phone],
            ['lang' => $this->shop['lang'] ?? BotLang::DEFAULT],
        );
    }

    private function servicesFor(string $platform)
    {
        return BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('platform', $platform)
            ->where('status', 'active')
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

    /** Panel prices are per 1000 units, which is how resellers quote them. */
    private function pricePerUnit(BotService $service): string
    {
        return bcdiv((string) $service->my_price, '1000', 4);
    }

    private function costOf(array $service, int $quantity): string
    {
        return bcdiv(bcmul((string) $service['my_price'], (string) $quantity, 4), '1000', 2);
    }

    private function quantityLabel(int $quantity): string
    {
        return $quantity >= 1000 ? number_format($quantity / 1000).'K' : (string) $quantity;
    }

    private function money(string|float $amount): string
    {
        return $this->currency.' '.number_format((float) $amount, 2);
    }
}
