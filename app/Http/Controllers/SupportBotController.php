<?php

namespace App\Http\Controllers;

use App\Enums\ServiceKey;
use App\Models\GuaranteeRule;
use App\Models\ResponseTemplate;
use App\Models\Subscription;
use App\Models\TenantPanel;
use App\Models\TenantWhatsApp;
use App\Models\Ticket;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotSettings;
use App\Services\Bots\Support\SupportAction;
use App\Services\Guarantee\RefillPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The support bot's console — the after-sales counterpart to OrderBotController.
 *
 * Same shape as the order bot's: one page, tabs, each tab's payload built only
 * when it is the one being shown. What differs is what a support reseller
 * actually watches, which is not sales but whether anybody is waiting on a
 * reply — so the overview leads with the handoff queue.
 *
 * Inbox and Tickets are big enough to own their own screens and controllers.
 */
class SupportBotController extends Controller
{
    private const BOT = 'support';

    private const TABS = ['overview', 'number', 'rules', 'templates', 'settings'];

    /**
     * Messages a reseller may reword, and what each one is for.
     *
     * Only keys the handler actually sends: a Templates screen offering keys
     * nothing reads would let a reseller carefully write text that never
     * appears. Each of these is passed as a template key at a `sendText` call
     * site, and the messenger resolves an override before sending.
     */
    private const TEMPLATE_KEYS = [
        'SUPPORT_MENU' => 'The Quick Menu itself, sent when a conversation opens.',
        'HUMAN_HANDOFF' => 'Confirmation that a person is taking over.',
        'STATUS_SUCCESS' => 'An order status the customer asked for.',
        'NOT_FOUND' => 'The order ID did not match anything.',
        'REFILL_SUCCESS' => 'A refill was submitted to the panel.',
        'REFILL_NO_GUARANTEE' => 'The service carries no refill guarantee.',
        'REFILL_ERROR' => 'The panel refused the refill.',
        'CANCEL_SUCCESS' => 'A cancellation request was logged.',
        'CANCEL_INVALID' => 'The order could not be found to cancel.',
        'SPEEDUP_SUCCESS' => 'A speed-up request was logged.',
        'PARTIAL_LOGGED' => 'A partial / fake-completion report was logged.',
        'TOPUP_HELP' => 'What to send when a top-up did not arrive.',
    ];

    public function show(Request $request, string $tab = 'overview'): Response
    {
        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, self::BOT);

        return Inertia::render('SupportBot/Index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'status' => $this->status($tenantId),

            ...match ($tab) {
                'number' => ['numbers' => \App\Services\Numbers\BotNumbers::for($tenantId, self::BOT)],
                'rules' => [
                    'rules' => $this->rules($tenantId),
                    'panels' => $this->panels($tenantId),
                    'refillPolicy' => $this->refillPolicy($settings),
                ],
                'templates' => [
                    'templates' => $this->templates($tenantId, $settings),
                    'languages' => $this->languages(),
                ],
                'settings' => [
                    'settings' => $this->settingsPayload($settings),
                    'languages' => $this->languages(),
                ],
                default => ['overview' => $this->overview($tenantId, $settings)],
            },
        ]);
    }

    /**
     * Is the bot answering right now?
     *
     * Three separate causes make a bot silent — no number, no subscription, a
     * number that is connected but not active — so they are reported apart
     * rather than as one boolean a reseller cannot act on.
     */
    private function status(int $tenantId): array
    {
        $number = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', self::BOT)
            ->first();

        $active = Subscription::isServiceActive($tenantId, ServiceKey::SupportBot);
        $sandbox = Subscription::isSandbox($tenantId, ServiceKey::SupportBot);

        return [
            'number' => $number?->display_number,
            'numberStatus' => $number?->status,
            'connected' => $number !== null,
            'subscription' => $active ? 'active' : ($sandbox ? 'sandbox' : 'inactive'),
            'live' => $number !== null && $number->status === 'active' && ($active || $sandbox),
        ];
    }

    /**
     * What a support reseller opens the page to find out.
     *
     * The handoff count leads because it is the only number here with somebody
     * on the other end of it: each one is a customer who asked for a person and
     * has not been answered yet.
     */
    private function overview(int $tenantId, array $settings): array
    {
        $counts = Ticket::statusCounts($tenantId);

        $number = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', self::BOT)
            ->first();

        return [
            'checks' => [
                'subscription' => Subscription::isUsable($tenantId, ServiceKey::SupportBot),
                'panel' => TenantPanel::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->exists(),
                'whatsapp' => $number !== null && $number->status === 'active',
                // Refill is decided by the reseller's rules or by what the
                // service says; with neither, every refill request is refused.
                'rules' => (bool) Arr::get($settings, 'refill.auto_read', true)
                    || GuaranteeRule::withoutTenantScope()
                        ->where('tenant_id', $tenantId)
                        ->where('status', 'active')
                        ->exists(),
            ],
            'sandbox' => Subscription::isSandbox($tenantId, ServiceKey::SupportBot),
            'testNumbers' => Arr::get($settings, 'shop.test_numbers', []),
            'tickets' => $counts,
            'awaitingHuman' => Ticket::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('handed_over_at')
                ->openish()
                ->count(),
            // What the menu currently offers, so a reseller can see the bot as
            // the customer does without opening WhatsApp.
            'menu' => array_map(
                fn (SupportAction $action) => [
                    'value' => $action->value,
                    'label' => $action->label(),
                    'toggle' => $action->toggleKey(),
                    'enabled' => $action->toggleKey() === null
                        || (bool) Arr::get($settings, "commands.{$action->toggleKey()}", false),
                ],
                SupportAction::cases(),
            ),
        ];
    }

    // ---- guarantee rules -------------------------------------------------

    /**
     * Refill guarantee rules, most specific first.
     *
     * `no_guarantee` rules are listed above `guarantee` ones because that is
     * the order the matcher applies them in — an exclusion beats a grant, and
     * a reseller reading the list top-down should see the same precedence the
     * bot uses.
     */
    private function rules(int $tenantId): array
    {
        $rules = GuaranteeRule::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with('panel:id,name')
            ->orderByRaw("rule_type = 'guarantee'")
            ->orderBy('keyword')
            ->get();

        // The matcher takes the earliest of two rules with the same keyword,
        // so a later copy never applies. Say so, rather than let a reseller
        // wonder why the 365-day rule they added is ignored.
        $earliest = [];

        foreach ($rules->where('status', 'active')->sortBy('id') as $rule) {
            $earliest[$rule->rule_type.'|'.mb_strtolower(trim($rule->keyword))] ??= $rule->id;
        }

        return $rules->map(fn (GuaranteeRule $rule) => [
            'id' => $rule->id,
            'panelId' => $rule->panel_id,
            'panelName' => $rule->panel?->name,
            'type' => $rule->rule_type,
            'keyword' => $rule->keyword,
            'refillDays' => $rule->refill_days,
            'status' => $rule->status,
            'shadowed' => $rule->status === 'active'
                && ($earliest[$rule->rule_type.'|'.mb_strtolower(trim($rule->keyword))] ?? $rule->id) !== $rule->id,
        ])->all();
    }

    /** How refill requests are decided when no rule matches. */
    private function refillPolicy(array $settings): array
    {
        return [
            'autoRead' => (bool) Arr::get($settings, 'refill.auto_read', true),
            'default' => (string) Arr::get($settings, 'refill.default', 'refuse'),
        ];
    }

    public function updateRefillPolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'autoRead' => ['required', 'boolean'],
            'default' => ['required', Rule::in(RefillPolicy::DEFAULTS)],
        ]);

        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, self::BOT);

        $settings['refill'] = ['auto_read' => $data['autoRead'], 'default' => $data['default']];

        BotSettings::save($tenantId, self::BOT, $settings);

        return back()->with('success', 'Refill settings saved.');
    }

    private function panels(int $tenantId): array
    {
        return TenantPanel::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (TenantPanel $panel) => ['id' => $panel->id, 'name' => $panel->name])
            ->all();
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $tenantId = (int) $request->user()->id;

        $data = $request->validate([
            'type' => ['required', Rule::in(['guarantee', 'no_guarantee'])],
            'keyword' => ['required', 'string', 'max:100'],
            // 0 means lifetime, which is why the floor is 0 and not 1.
            'refillDays' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'panelId' => [
                'nullable',
                // Scoped to this tenant: an unscoped exists() would let a
                // reseller attach a rule to somebody else's panel.
                Rule::exists('tenant_panels', 'id')->where('tenant_id', $tenantId),
            ],
        ]);

        $keyword = trim($data['keyword']);

        // The matcher ignores capitals, so "Instagram" and "instagram" are the
        // same rule — and the second would never be used.
        $duplicate = GuaranteeRule::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('panel_id', $data['panelId'] ?? null)
            ->whereRaw('lower(keyword) = ?', [mb_strtolower($keyword)])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'keyword' => "You already have a rule for “{$keyword}”. Change or remove that one instead.",
            ]);
        }

        GuaranteeRule::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'panel_id' => $data['panelId'] ?? null,
            'rule_type' => $data['type'],
            'keyword' => trim($data['keyword']),
            'refill_days' => $data['type'] === 'guarantee' ? ($data['refillDays'] ?? 0) : null,
            'status' => 'active',
        ]);

        return back()->with('success', 'Rule added.');
    }

    public function updateRule(Request $request, GuaranteeRule $rule): RedirectResponse
    {
        $this->authorizeRule($request, $rule);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'keyword' => ['sometimes', 'string', 'max:100'],
            'refillDays' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        if (array_key_exists('status', $data)) {
            $rule->status = $data['status'];
        }

        if (array_key_exists('keyword', $data)) {
            $rule->keyword = trim($data['keyword']);
        }

        if (array_key_exists('refillDays', $data) && $rule->rule_type === 'guarantee') {
            $rule->refill_days = $data['refillDays'];
        }

        $rule->save();

        return back()->with('success', 'Rule updated.');
    }

    public function destroyRule(Request $request, GuaranteeRule $rule): RedirectResponse
    {
        $this->authorizeRule($request, $rule);

        $rule->delete();

        return back()->with('success', 'Rule removed.');
    }

    // ---- templates -------------------------------------------------------

    /**
     * The bot's messages, with the reseller's override where one exists.
     *
     * Both are returned: `content` is what the bot sends today, `custom` is
     * whether that is the reseller's wording or the built-in default. Without
     * the distinction a reseller cannot tell what they have changed, and
     * "reset to default" has nothing to mean.
     */
    private function templates(int $tenantId, array $settings): array
    {
        $lang = BotLang::normalize(Arr::get($settings, 'shop.lang', BotLang::DEFAULT));

        $overrides = ResponseTemplate::where('tenant_id', $tenantId)
            ->where('lang', $lang)
            ->pluck('content', 'template_key');

        return [
            'lang' => $lang,
            'rows' => collect(self::TEMPLATE_KEYS)->map(fn (string $description, string $key) => [
                'key' => $key,
                'description' => $description,
                'content' => $overrides[$key] ?? '',
                'custom' => $overrides->has($key),
            ])->values()->all(),
        ];
    }

    /**
     * Save one template, or clear it back to the built-in default.
     *
     * Empty content deletes the row rather than storing a blank: an empty
     * override would otherwise mean the bot sends nothing at all, and a
     * reseller clearing a box means "use the default", not "say nothing".
     */
    public function updateTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(array_keys(self::TEMPLATE_KEYS))],
            'lang' => ['required', Rule::in(BotLang::SUPPORTED)],
            'content' => ['nullable', 'string', 'max:4000'],
        ]);

        $tenantId = (int) $request->user()->id;
        $content = trim((string) ($data['content'] ?? ''));

        if ($content === '') {
            ResponseTemplate::where('tenant_id', $tenantId)
                ->where('template_key', $data['key'])
                ->where('lang', $data['lang'])
                ->delete();

            return back()->with('success', 'Reset to the default wording.');
        }

        ResponseTemplate::updateOrCreate(
            ['tenant_id' => $tenantId, 'template_key' => $data['key'], 'lang' => $data['lang']],
            ['content' => $content, 'is_default' => false],
        );

        return back()->with('success', 'Template saved.');
    }

    // ---- settings --------------------------------------------------------

    /** @return array<int, array{code: string, name: string}> */
    private function languages(): array
    {
        return array_map(
            fn (string $code) => ['code' => $code, 'name' => BotLang::NAMES[$code] ?? $code],
            BotLang::SUPPORTED,
        );
    }

    private function settingsPayload(array $settings): array
    {
        return [
            'commands' => Arr::get($settings, 'commands', []),
            'spam' => Arr::get($settings, 'spam', []),
            'staff' => Arr::get($settings, 'staff.numbers', []),
            'testNumbers' => Arr::get($settings, 'shop.test_numbers', []),
            'lang' => Arr::get($settings, 'shop.lang', BotLang::DEFAULT),
        ];
    }

    public function updateSettings(Request $request): RedirectResponse
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
            'staff' => ['array'],
            'staff.*' => ['string', 'max:20'],
            'lang' => ['required', Rule::in(BotLang::SUPPORTED)],
        ]);

        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, self::BOT);

        // Written key by key: `shop` also holds settings this form never sees,
        // and assigning the whole sub-array would erase them.
        $settings['commands'] = $data['commands'];
        $settings['spam'] = $data['spam'];
        Arr::set($settings, 'staff.numbers', array_values($data['staff'] ?? []));
        Arr::set($settings, 'shop.lang', $data['lang']);

        BotSettings::save($tenantId, self::BOT, $settings);

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Test numbers, saved on their own card.
     *
     * While the subscription is in sandbox these are the only numbers the bot
     * answers, which is how a reseller tries it before paying.
     */
    public function updateTestNumbers(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'testNumbers' => ['array'],
            'testNumbers.*' => ['string', 'max:20'],
        ]);

        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, self::BOT);

        // Arr::set returns the innermost array it walked into, not the whole
        // one — so it is called for its effect and $settings passed on.
        Arr::set($settings, 'shop.test_numbers', array_values($data['testNumbers'] ?? []));

        BotSettings::save($tenantId, self::BOT, $settings);

        return back()->with('success', 'Test numbers saved.');
    }

    private function authorizeRule(Request $request, GuaranteeRule $rule): void
    {
        abort_unless((int) $rule->tenant_id === (int) $request->user()->id, 404);
    }
}
