<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\ApiLog;
use App\Models\BotCustomer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a reseller hands out API access to their customers.
 *
 * Three tabs, each a URL: the keys they have issued, the documentation they
 * will send someone, and the log of what those keys have been doing. The log
 * is not an afterthought — the caller is someone else's code on someone
 * else's server, and without it "it isn't working" has no answer.
 */
class ApiAccessController extends Controller
{
    private const TABS = ['keys', 'docs', 'logs'];

    /** A page of log rows. Enough to see a pattern, not enough to be a report. */
    private const LOGS_PER_PAGE = 50;

    public function show(Request $request, string $tab = 'keys'): Response
    {
        return Inertia::render('Api/Index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'endpoint' => url('/api/v2'),

            // Only the tab being viewed is built. The logs query is the
            // expensive one and has no business running because someone
            // opened the docs.
            ...match ($tab) {
                'docs' => $this->docsPayload(),
                'logs' => $this->logsPayload($request),
                default => $this->keysPayload(),
            },
        ]);
    }

    /**
     * Issue a key for one of the reseller's customers.
     *
     * The plaintext goes back in the session, once. It is deliberately not
     * stored anywhere it could be read again — a reseller who loses it issues
     * another, which is also how a key is rotated.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => [
                'required',
                // Scoped to the tenant's own customers: an id from the request
                // must never reach across resellers.
                Rule::exists('bot_customers', 'id')->where('tenant_id', $request->user()->id),
            ],
            'label' => ['nullable', 'string', 'max:60'],
        ]);

        $customer = BotCustomer::findOrFail($validated['customer_id']);

        [, $plaintext] = ApiKey::issue($customer, $validated['label'] ?? null);

        return back()->with('newApiKey', $plaintext);
    }

    /**
     * Revoke rather than delete: the logs point at the key, and a reseller
     * asking "what did this key do before I turned it off" should get an
     * answer.
     */
    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $apiKey->update(['status' => ApiKey::REVOKED]);

        return back()->with('success', 'That key has been revoked.');
    }

    public function update(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:6000'],
            'ip_allowlist' => ['nullable', 'array', 'max:20'],
            'ip_allowlist.*' => ['string', 'ip'],
        ]);

        $apiKey->update([
            'label' => $validated['label'] ?? null,
            'rate_limit' => $validated['rate_limit'] ?? null,
            // An empty list is stored as null — "no restriction" — rather than
            // as [], which reads as "nobody" and would be a way to lock a
            // customer out by saving an unfinished form. ApiKey::allowsIp
            // treats both the same; this keeps the stored value honest.
            'ip_allowlist' => empty($validated['ip_allowlist']) ? null : array_values($validated['ip_allowlist']),
        ]);

        return back()->with('success', 'Key updated.');
    }

    private function keysPayload(): array
    {
        return [
            'keys' => ApiKey::query()
                ->with('customer:id,name,phone')
                ->orderByDesc('id')
                ->get()
                ->map(fn (ApiKey $key) => [
                    'id' => $key->id,
                    'prefix' => $key->key_prefix,
                    'label' => $key->label,
                    'status' => $key->status,
                    'rateLimit' => $key->rate_limit,
                    'defaultRateLimit' => ApiKey::DEFAULT_RATE_LIMIT,
                    'ipAllowlist' => $key->ip_allowlist ?? [],
                    'lastUsedAt' => $key->last_used_at?->toIso8601String(),
                    'lastUsedIp' => $key->last_used_ip,
                    'createdAt' => $key->created_at?->toIso8601String(),
                    'customer' => $key->customer === null ? null : [
                        'id' => $key->customer->id,
                        'name' => $key->customer->name,
                        'phone' => $key->customer->phone,
                    ],
                ])
                ->all(),

            // Who a key can be issued to. Capped, with a search, because a
            // reseller with thousands of customers should not ship all of them
            // to the browser to fill one dropdown.
            'customers' => BotCustomer::query()
                ->whereNull('blocked_at')
                ->orderByDesc('last_seen_at')
                ->limit(100)
                ->get(['id', 'name', 'phone', 'balance'])
                ->map(fn (BotCustomer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'balance' => (string) $customer->balance,
                ])
                ->all(),
        ];
    }

    /**
     * The documentation is written here rather than generated, because it is
     * prose a reseller forwards to a developer — worked examples and the
     * reasons behind them, which no schema dump produces.
     */
    private function docsPayload(): array
    {
        return [
            'actions' => [
                [
                    'action' => 'services',
                    'summary' => 'List everything on sale, with prices.',
                    'params' => [],
                    'example' => '[{"service":"12","name":"Instagram Followers","rate":"2.0000","min":"100","max":"5000"}]',
                ],
                [
                    'action' => 'add',
                    'summary' => 'Place an order. The balance is charged straight away.',
                    'params' => [
                        ['name' => 'service', 'note' => 'Service ID from the services list'],
                        ['name' => 'link', 'note' => 'The profile or post to deliver to'],
                        ['name' => 'quantity', 'note' => 'Within the service min and max'],
                    ],
                    'example' => '{"order":1042}',
                ],
                [
                    'action' => 'status',
                    'summary' => 'Check one order.',
                    'params' => [['name' => 'order', 'note' => 'The order ID from add']],
                    'example' => '{"charge":"2.0000","start_count":"0","status":"In progress","remains":"0","currency":"USD"}',
                ],
                [
                    'action' => 'status',
                    'summary' => 'Check up to 100 orders in one call — send orders instead of order.',
                    'params' => [['name' => 'orders', 'note' => 'Comma-separated order IDs']],
                    'example' => '{"1042":{"status":"Completed"},"1043":{"error":"Incorrect order ID"}}',
                ],
                [
                    'action' => 'refill',
                    'summary' => 'Ask for a refill on a delivered order.',
                    'params' => [['name' => 'order', 'note' => 'The order ID']],
                    'example' => '{"refill":"1042"}',
                ],
                [
                    'action' => 'balance',
                    'summary' => 'What is left in the wallet.',
                    'params' => [],
                    'example' => '{"balance":"98.00","currency":"USD"}',
                ],
            ],
        ];
    }

    private function logsPayload(Request $request): array
    {
        $logs = ApiLog::query()
            ->with('apiKey:id,key_prefix,label')
            ->when(
                $request->string('key')->isNotEmpty(),
                fn ($query) => $query->where('api_key_id', $request->integer('key')),
            )
            // "Show me what went wrong" is the reason this screen is open most
            // of the time.
            ->when($request->boolean('failed'), fn ($query) => $query->where('ok', false))
            ->orderByDesc('id')
            ->paginate(self::LOGS_PER_PAGE)
            ->withQueryString()
            ->through(fn (ApiLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'ok' => $log->ok,
                'error' => $log->error,
                'ip' => $log->ip,
                'details' => $log->details,
                'durationMs' => $log->duration_ms,
                'createdAt' => $log->created_at?->toIso8601String(),
                'key' => $log->apiKey === null ? null : [
                    'id' => $log->apiKey->id,
                    'prefix' => $log->apiKey->key_prefix,
                    'label' => $log->apiKey->label,
                ],
            ]);

        return [
            'logs' => $logs,
            'filters' => [
                'key' => $request->string('key')->toString(),
                'failed' => $request->boolean('failed'),
            ],

            // Enough of each key to populate the filter. Revoked ones are
            // included deliberately: the reason to read a log is often
            // something a key did before it was turned off.
            'keys' => ApiKey::query()
                ->orderByDesc('id')
                ->get(['id', 'key_prefix', 'label'])
                ->map(fn (ApiKey $key) => [
                    'id' => $key->id,
                    'prefix' => $key->key_prefix,
                    'label' => $key->label,
                ])
                ->all(),
        ];
    }
}
