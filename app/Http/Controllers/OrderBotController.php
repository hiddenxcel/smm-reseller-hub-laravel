<?php

namespace App\Http\Controllers;

use App\Enums\ServiceKey;
use App\Models\BotMessage;
use App\Models\Subscription;
use App\Models\TenantAi;
use App\Models\TenantPanel;
use App\Models\TenantWhatsApp;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotSettings;
use App\Services\Payments\ExchangeRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The order bot's own console.
 *
 * The bot is a product a reseller buys on its own — `ServiceKey::OrderBot` has
 * its own subscription, and `one_bot_per_whatsapp_number` gives it its own
 * number — so it gets a page rather than a corner of Setup.
 *
 * What it deliberately does NOT own: the catalogue, the orders list, and the
 * payment gateways. Those are shared with the support bot and already have
 * their own screens; duplicating them here would give a reseller two places to
 * edit one thing. Overview links out to them instead.
 */
class OrderBotController extends Controller
{
    private const BOT = 'order';

    private const TABS = ['setup', 'number', 'commands', 'logs', 'settings'];

    public function show(Request $request, string $tab = 'setup'): Response
    {
        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, self::BOT);

        return Inertia::render('OrderBot/Index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'status' => $this->status($tenantId),

            // Each tab's payload is built only when it is the one being shown.
            // The log query in particular has no business running because
            // someone opened Commands.
            ...match ($tab) {
                'number' => ['numbers' => \App\Services\Numbers\BotNumbers::for($tenantId, self::BOT)],
                'commands' => ['commands' => Arr::get($settings, 'commands', []), 'spam' => Arr::get($settings, 'spam', [])],
                'logs' => ['logs' => $this->logs($request, $tenantId)],
                'settings' => [
                    'settings' => $this->settingsPayload($settings, $request->user()),
                    'languages' => $this->languages(),
                    // The shop's currency is picked, not typed: only these have a
                    // rate, so only these can be charged through a gateway.
                    'currencies' => ExchangeRates::catalogue(),
                ],
                default => [
                    'setup' => $this->setup($tenantId, $settings),
                    'languages' => $this->languages(),
                ],
            },
        ]);
    }

    /**
     * Is the bot actually answering right now?
     *
     * Three things have to hold — a number, a subscription, and a live status —
     * and a reseller whose bot is silent needs to know which one broke, so they
     * are reported separately rather than as one boolean.
     */
    private function status(int $tenantId): array
    {
        $number = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', self::BOT)
            ->first();

        $active = Subscription::isServiceActive($tenantId, ServiceKey::OrderBot);
        $sandbox = Subscription::isSandbox($tenantId, ServiceKey::OrderBot);

        return [
            'number' => $number?->display_number,
            'numberStatus' => $number?->status,
            'connected' => $number !== null,
            'subscription' => $active ? 'active' : ($sandbox ? 'sandbox' : 'inactive'),
            // Sandbox answers only the reseller's own test numbers, so it is
            // live in the sense that matters here: messages get replies.
            'live' => $number !== null && $number->status === 'active' && ($active || $sandbox),
        ];
    }

    /**
     * Everything the setup screen needs: what is still missing before the bot
     * can sell, and the handful of settings that shape how it talks.
     *
     * The readiness checks are the point. A bot that is silent has exactly one
     * of three causes — no subscription, no panel to buy from, no number to
     * answer on — and naming which one saves a reseller from guessing.
     */
    private function setup(int $tenantId, array $settings): array
    {
        $number = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', self::BOT)
            ->first();

        // AI support is a separate paid add-on, and it needs a key of its own.
        // Both have to hold, so the screen reports them separately.
        $aiActive = Subscription::isServiceActive($tenantId, ServiceKey::AiChat);
        $ai = TenantAi::forTenant($tenantId);

        return [
            'checks' => [
                'subscription' => Subscription::isUsable($tenantId, ServiceKey::OrderBot),
                'panel' => TenantPanel::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->exists(),
                'whatsapp' => $number !== null && $number->status === 'active',
            ],
            'sandbox' => Subscription::isSandbox($tenantId, ServiceKey::OrderBot),
            'testNumbers' => Arr::get($settings, 'shop.test_numbers', []),
            'lang' => Arr::get($settings, 'shop.lang', BotLang::DEFAULT),
            'groupUrl' => Arr::get($settings, 'shop.group_url', ''),
            'websiteUrl' => Arr::get($settings, 'shop.website_url', ''),
            'supportMode' => Arr::get($settings, 'shop.support_mode', 'admin') === 'ai' ? 'ai' : 'admin',
            'autoRefund' => (bool) Arr::get($settings, 'shop.auto_refund', true),
            'staff' => Arr::get($settings, 'staff.numbers', []),
            'ai' => [
                'active' => $aiActive,
                'hasKey' => $ai?->deepseek_api_key_enc !== null,
                // The reseller's own DeepSeek bill is invisible to us, so
                // these counts are the only thing connecting it to their bot.
                'answersToday' => (int) $ai?->answersToday(),
                'answersTotal' => (int) $ai?->answers_total,
            ],
        ];
    }

    /** @return array<int, array{code: string, name: string}> */
    private function languages(): array
    {
        return array_map(
            fn (string $code) => ['code' => $code, 'name' => BotLang::NAMES[$code] ?? $code],
            BotLang::SUPPORTED,
        );
    }

    /**
     * The message log, newest first.
     *
     * Paged rather than capped: "did they ever message me?" is a question about
     * old messages, and a hard limit is exactly what hides the answer.
     */
    private function logs(Request $request, int $tenantId): array
    {
        $search = trim((string) $request->query('q', ''));

        $logs = BotMessage::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', self::BOT)
            ->when($search !== '', fn ($q) => $q->where(
                fn ($inner) => $inner->where('customer_phone', 'like', "%{$search}%")
                    ->orWhere('message', 'ilike', "%{$search}%"),
            ))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return [
            'q' => $search,
            'rows' => collect($logs->items())->map(fn (BotMessage $row) => [
                'id' => $row->id,
                'phone' => $row->customer_phone,
                'direction' => $row->direction,
                'message' => $row->message,
                'at' => $row->created_at?->toIso8601String(),
            ])->all(),
            'page' => $logs->currentPage(),
            'lastPage' => $logs->lastPage(),
            'total' => $logs->total(),
        ];
    }

    /** Only the keys the settings tab edits — the rest stay untouched on save. */
    private function settingsPayload(array $settings, \App\Models\Tenant $tenant): array
    {
        return [
            'staffAlerts' => app(\App\Services\Bots\StaffAlerts::class)->overview($tenant, self::BOT),
            'staff' => Arr::get($settings, 'staff.numbers', []),
            'testNumbers' => Arr::get($settings, 'shop.test_numbers', []),
            'currency' => Arr::get($settings, 'shop.currency', 'USD'),
            'lang' => Arr::get($settings, 'shop.lang', BotLang::DEFAULT),
            'minTopup' => Arr::get($settings, 'shop.min_topup', 1),
            'referralPercent' => Arr::get($settings, 'shop.referral_percent', 0),
            'showProviderName' => Arr::get($settings, 'response.show_provider_name', false),
            'detailedStatus' => Arr::get($settings, 'response.detailed_status', true),
        ];
    }

    /**
     * The language/links/support card.
     *
     * Kept apart from the test-numbers card below rather than saved as one
     * screen: each card owns its own fields, so saving one never overwrites
     * what the other holds.
     */
    public function updateSetup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lang' => ['required', Rule::in(BotLang::SUPPORTED)],
            'groupUrl' => ['nullable', 'url', 'max:255'],
            'websiteUrl' => ['nullable', 'url', 'max:255'],
            'supportMode' => ['required', Rule::in(['admin', 'ai'])],
            'staff' => ['array'],
            'staff.*' => ['string', 'max:20'],
            'autoRefund' => ['sometimes', 'boolean'],
        ]);

        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, self::BOT);

        if (array_key_exists('autoRefund', $data)) {
            Arr::set($settings, 'shop.auto_refund', (bool) $data['autoRefund']);
        }

        Arr::set($settings, 'shop.lang', $data['lang']);
        Arr::set($settings, 'shop.group_url', $data['groupUrl'] ?? '');
        Arr::set($settings, 'shop.website_url', $data['websiteUrl'] ?? '');
        Arr::set($settings, 'shop.support_mode', $data['supportMode']);
        Arr::set($settings, 'staff.numbers', array_values($data['staff'] ?? []));

        BotSettings::save($tenantId, self::BOT, $settings);

        return back()->with('success', 'Bot setup saved.');
    }

    /**
     * Test numbers. While the subscription is in sandbox these are the only
     * numbers the bot answers, which is how a reseller tries it before paying.
     */
    public function updateTestNumbers(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'testNumbers' => ['array'],
            'testNumbers.*' => ['string', 'max:20'],
        ]);

        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, self::BOT);

        Arr::set($settings, 'shop.test_numbers', array_values($data['testNumbers'] ?? []));

        BotSettings::save($tenantId, self::BOT, $settings);

        return back()->with('success', 'Test numbers saved.');
    }

    public function updateCommands(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'commands' => ['required', 'array'],
            'commands.refill' => ['required', 'boolean'],
            'commands.status' => ['required', 'boolean'],
            'commands.cancel' => ['required', 'boolean'],
            'commands.speedup' => ['required', 'boolean'],
            'spam' => ['required', 'array'],
            'spam.enabled' => ['required', 'boolean'],
            // A threshold of 1 would block a customer for saying hello twice.
            'spam.repeat_threshold' => ['required', 'integer', 'min:2', 'max:20'],
            'spam.window_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'spam.disable_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $this->merge($request, [
            'commands' => $data['commands'],
            'spam' => $data['spam'],
        ]);

        return back()->with('success', 'Commands updated.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $settings = BotSettings::for((int) $request->user()->id, self::BOT);

        // Case does not matter to the person choosing; it does to the lookup.
        if (is_string($request->input('currency'))) {
            $request->merge(['currency' => strtoupper(trim($request->input('currency')))]);
        }

        // What the shop already has stays valid. A currency saved back when the
        // field was free text may not be one we can convert — rejecting it
        // would block every other setting from being saved until it was
        // changed, which punishes the reseller for an old choice.
        $allowedCurrencies = array_unique([
            ...array_column(ExchangeRates::catalogue(), 'code'),
            (string) Arr::get($settings, 'shop.currency', 'USD'),
        ]);

        $data = $request->validate([
            'staff' => ['array'],
            'staff.*' => ['string', 'max:20'],
            'testNumbers' => ['array'],
            'testNumbers.*' => ['string', 'max:20'],
            'currency' => ['required', 'string', Rule::in($allowedCurrencies)],
            'lang' => ['required', Rule::in(BotLang::SUPPORTED)],
            'minTopup' => ['required', 'numeric', 'min:0'],
            'referralPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'showProviderName' => ['required', 'boolean'],
            'detailedStatus' => ['required', 'boolean'],
        ]);

        // Written key by key rather than as one nested array: `shop` also holds
        // gateway ids and support links this form never sees, and assigning the
        // whole sub-array would erase them.
        Arr::set($settings, 'staff.numbers', array_values($data['staff'] ?? []));
        Arr::set($settings, 'shop.test_numbers', array_values($data['testNumbers'] ?? []));
        Arr::set($settings, 'shop.currency', strtoupper($data['currency']));
        Arr::set($settings, 'shop.lang', $data['lang']);
        Arr::set($settings, 'shop.min_topup', $data['minTopup']);
        Arr::set($settings, 'shop.referral_percent', $data['referralPercent']);
        Arr::set($settings, 'response.show_provider_name', $data['showProviderName']);
        Arr::set($settings, 'response.detailed_status', $data['detailedStatus']);

        BotSettings::save((int) $request->user()->id, self::BOT, $settings);

        return back()->with('success', 'Settings saved.');
    }

    /** Merge top-level setting groups without disturbing the others. */
    private function merge(Request $request, array $groups): void
    {
        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, self::BOT);

        foreach ($groups as $key => $value) {
            $settings[$key] = $value;
        }

        BotSettings::save($tenantId, self::BOT, $settings);
    }
}
