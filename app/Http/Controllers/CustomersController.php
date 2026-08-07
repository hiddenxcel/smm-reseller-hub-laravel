<?php

namespace App\Http\Controllers;

use App\Models\BotCustomer;
use App\Services\Customers\BulkCustomerAction;
use App\Services\Customers\CustomerActions;
use App\Services\Customers\CustomerFilters;
use App\Services\Customers\CustomerMessaging;
use App\Services\Customers\CustomerProfile;
use App\Services\Customers\CustomerQuery;
use App\Services\Customers\CustomerReferrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customers screen: who has bought from this reseller, and everything
 * about them.
 *
 * Like orders, the whole list state lives in the query string so a filtered
 * view is a shareable URL. The slide-over's tabs are fetched one at a time
 * over JSON — opening a customer should cost one query, not six.
 */
class CustomersController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user();
        $filters = CustomerFilters::fromRequest($request);
        $query = CustomerQuery::for($tenant);

        $page = $query->paginate($filters);

        // One grouped query for the page's bot badges rather than one per row.
        $bots = $query->botUsageFor(
            collect($page->items())->pluck('phone')->all()
        );

        return Inertia::render('Customers/Index', [
            'customers' => [
                'data' => collect($page->items())
                    ->map(fn (BotCustomer $customer) => CustomerQuery::toRow(
                        $customer,
                        $bots[$customer->phone] ?? [],
                    ))
                    ->all(),
                'meta' => [
                    'currentPage' => $page->currentPage(),
                    'lastPage' => $page->lastPage(),
                    'perPage' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ],
            'filters' => $filters->toArray(),
            'isFiltered' => $filters->isFiltered(),
            // Deferred: the KPI row is several aggregates, and the table is
            // more useful on screen a moment before they land.
            'kpis' => Inertia::defer(fn () => $query->kpis()),
            'tabCounts' => Inertia::defer(fn () => $query->tabCounts($filters)),
            'options' => Inertia::defer(fn () => $query->filterOptions()),
            'pageSizes' => CustomerQuery::PAGE_SIZES,
            'walletBands' => CustomerQuery::WALLET_BANDS,
            'bulkLimits' => [
                'default' => BulkCustomerAction::MAX_SELECTION,
                'broadcast' => BulkCustomerAction::MAX_BROADCAST,
            ],
            'hasWhatsApp' => CustomerMessaging::for($tenant)->orderNumber() !== null,
        ]);
    }

    /**
     * One tab of the slide-over.
     *
     * Plain JSON rather than an Inertia visit: the page is not navigating, it
     * is filling in a panel over the list that is already there.
     */
    public function show(Request $request, BotCustomer $customer, string $tab = 'overview'): JsonResponse
    {
        $profile = CustomerProfile::for($customer);
        $messaging = CustomerMessaging::for($request->user());

        $payload = match ($tab) {
            'orders' => ['orders' => $profile->orders()],
            'messages' => [
                'messages' => $profile->messages(),
                // The reply box is disabled outside Meta's 24-hour window, so
                // the panel needs to know before the reseller types.
                'canReply' => $messaging->isWithinWindow($customer),
                'windowClosesAt' => $messaging->windowClosesAt($customer)?->toIso8601String(),
            ],
            'wallet' => ['wallet' => $profile->wallet()],
            'tickets' => ['tickets' => $profile->tickets()],
            'activity' => ['activity' => $profile->activity()],
            default => ['overview' => $profile->overview()],
        };

        return response()->json($payload);
    }

    public function update(Request $request, BotCustomer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'lang' => ['nullable', 'string', 'max:5'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'array', 'max:20'],
            // `nullable` rather than `string`: the tag editor can post an empty
            // entry, and normaliseTags drops those. Refusing the whole save for
            // a blank the user cannot see would be a dead end.
            'tags.*' => ['nullable', 'string', 'max:40'],
        ]);

        $outcome = CustomerActions::update($customer, $validated);

        return $this->back($outcome);
    }

    public function destroy(BotCustomer $customer): RedirectResponse
    {
        return $this->back(CustomerActions::delete($customer));
    }

    /** Wallet, block, unblock — the single-customer actions. */
    public function act(Request $request, BotCustomer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['wallet', 'block', 'unblock', 'message'])],
            'amount' => ['required_if:action,wallet', 'nullable', 'numeric', 'not_in:0'],
            'reason' => ['nullable', 'string', 'max:190'],
            'text' => ['required_if:action,message', 'nullable', 'string', 'max:4000'],
        ]);

        $outcome = match ($validated['action']) {
            'wallet' => CustomerActions::adjustWallet(
                $customer,
                // Normalised to a fixed-point string before it goes anywhere
                // near money arithmetic — a float here would round.
                number_format((float) $validated['amount'], 2, '.', ''),
                $validated['reason'] ?? null,
            ),
            'block' => CustomerActions::block($customer),
            'unblock' => CustomerActions::unblock($customer),
            'message' => CustomerMessaging::for($request->user())
                ->send($customer, (string) $validated['text']),
        };

        return $this->back($outcome);
    }

    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(BulkCustomerAction::ALL)],
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkCustomerAction::MAX_SELECTION],
            'ids.*' => ['integer'],
            'tag' => ['required_if:action,tag,untag', 'nullable', 'string', 'max:40'],
            'text' => ['required_if:action,broadcast', 'nullable', 'string', 'max:4000'],
        ]);

        $outcome = BulkCustomerAction::for($request->user())->run(
            $validated['action'],
            $validated['ids'],
            ['tag' => $validated['tag'] ?? null, 'text' => $validated['text'] ?? null],
        );

        return Redirect::back()->with(
            $outcome['done'] === 0 ? 'error' : 'success',
            $outcome['message'],
        );
    }

    /** Every id the current filter matches, for "select all N". */
    public function matchingIds(Request $request): JsonResponse
    {
        $filters = CustomerFilters::fromRequest($request);

        return response()->json([
            'ids' => CustomerQuery::for($request->user())
                ->matchingIds($filters, BulkCustomerAction::MAX_SELECTION),
            'limit' => BulkCustomerAction::MAX_SELECTION,
        ]);
    }

    /**
     * Add a customer by hand.
     *
     * Rare but real: a reseller who took an order over the phone needs the
     * person to exist before they can put money in their wallet. The bot would
     * otherwise only create them on their first message.
     */
    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->user();

        $validated = $request->validate([
            'phone' => [
                'required', 'string', 'max:30',
                Rule::unique('bot_customers', 'phone')->where('tenant_id', $tenant->id),
            ],
            'name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'lang' => ['nullable', 'string', 'max:5'],
        ]);

        $customer = BotCustomer::create([
            'tenant_id' => $tenant->id,
            'phone' => preg_replace('/\D+/', '', $validated['phone']),
            'name' => $validated['name'] ?? null,
            'email' => $validated['email'] ?? null,
            'country' => isset($validated['country'])
                ? mb_strtoupper($validated['country'])
                : null,
            'lang' => $validated['lang'] ?? 'en',
        ]);

        CustomerReferrals::assignCode($customer);

        return Redirect::back()->with('success', 'Customer added.');
    }

    public function export(Request $request)
    {
        $tenant = $request->user();
        $filters = CustomerFilters::fromRequest($request);
        $filename = 'customers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($tenant, $filters) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID', 'Name', 'Phone', 'Email', 'Country', 'Language',
                'Orders', 'Spent', 'Wallet', 'Referral code', 'Referred by',
                'Tags', 'Blocked', 'Last seen', 'Joined',
            ]);

            CustomerQuery::for($tenant)->exportChunks($filters, function (BotCustomer $customer) use ($out) {
                fputcsv($out, [
                    $customer->id,
                    $customer->name,
                    $customer->phone,
                    $customer->email,
                    $customer->country,
                    $customer->lang,
                    $customer->orders_count ?? 0,
                    $customer->total_spent,
                    $customer->balance,
                    $customer->referral_code,
                    $customer->referred_by,
                    implode(' | ', $customer->tags ?? []),
                    $customer->blocked_at?->toDateTimeString(),
                    $customer->last_seen_at?->toDateTimeString(),
                    $customer->created_at?->toDateTimeString(),
                ]);
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function back(\App\Services\Customers\ActionOutcome $outcome): RedirectResponse
    {
        return Redirect::back()->with(
            $outcome->failed ? 'error' : 'success',
            $outcome->message,
        );
    }
}
