<?php

namespace App\Http\Controllers;

use App\Models\BotService;
use App\Models\PricingRule;
use App\Models\ServicePriceHistory;
use App\Models\TenantPanel;
use App\Services\Catalogue\BulkAdjustment;
use App\Services\Catalogue\BulkServiceAction;
use App\Services\Catalogue\PanelSync;
use App\Services\Catalogue\PricingEngine;
use App\Services\Catalogue\ServiceActions;
use App\Services\Catalogue\ServiceFilters;
use App\Services\Catalogue\ServiceProfile;
use App\Services\Catalogue\ServiceQuery;
use App\Services\Customers\ActionOutcome;
use App\Services\Panel\ServiceCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The services screen: what the bot sells, and for how much.
 *
 * This is where a reseller's margin lives, so the two things it has to get
 * right are that prices are never changed without a record, and that a bulk
 * sweep shows exactly what it will do before it does it.
 */
class ServicesController extends Controller
{
    public function __construct(private ServiceCatalogue $catalogue) {}

    public function index(Request $request): Response
    {
        $tenant = $request->user();
        $filters = ServiceFilters::fromRequest($request);
        $query = ServiceQuery::for($tenant);

        $page = $query->paginate($filters);
        $services = collect($page->items());
        $orderCounts = $query->orderCountsFor($services);

        return Inertia::render('Services/Index', [
            'services' => [
                'data' => $services
                    ->map(fn (BotService $service) => ServiceQuery::toRow(
                        $service,
                        $orderCounts[ServiceQuery::orderKey(
                            $service->panel_id,
                            $service->provider_service_id,
                        )] ?? 0,
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
            // Deferred: the KPI row and the sidebar are aggregates over the
            // whole catalogue, and the table is more useful a moment sooner.
            'kpis' => Inertia::defer(fn () => $query->kpis()),
            'tabCounts' => Inertia::defer(fn () => $query->tabCounts($filters)),
            'platforms' => Inertia::defer(fn () => $query->platformCounts($filters)),
            'categories' => Inertia::defer(fn () => $query->categoryCounts($filters)),
            'panels' => $query->panelOptions(),
            'rules' => $this->rulesFor($request),
            'pageSizes' => ServiceQuery::PAGE_SIZES,
            'marginBands' => ServiceQuery::MARGIN_BANDS,
            'bulkLimits' => [
                'default' => BulkServiceAction::MAX_SELECTION,
                'pricing' => BulkServiceAction::MAX_PRICING_SELECTION,
            ],
        ]);
    }

    /** One tab of the drawer. */
    public function show(Request $request, BotService $service, string $tab = 'overview'): JsonResponse
    {
        $profile = ServiceProfile::for($service);

        return response()->json(match ($tab) {
            'pricing' => ['pricing' => $profile->pricing()],
            'orders' => ['orders' => $profile->orders()],
            'logs' => ['logs' => $profile->history()],
            'settings' => ['settings' => $profile->settings()],
            default => ['overview' => $profile->overview()],
        });
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'platform' => ['required', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:80'],
            'provider_service_id' => ['required', 'string', 'max:50'],
            'panel_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit_label' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            // Zero would have the bot sell for nothing.
            'my_price' => ['required', 'numeric', 'gt:0'],
            'min_quantity' => ['required', 'integer', 'min:1'],
            'max_quantity' => ['required', 'integer', 'min:1'],
            'link_instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        $panelId = $this->panelIdFor($request, $validated['panel_id'] ?? null);

        $service = BotService::create([
            'tenant_id' => $tenant->id,
            'panel_id' => $panelId,
            'provider_service_id' => $validated['provider_service_id'],
            'platform' => $validated['platform'],
            'category' => $validated['category'] ?? null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'unit_label' => $validated['unit_label'] ?? 'Followers',
            'cost_price' => $validated['cost_price'] ?? null,
            'my_price' => $validated['my_price'],
            'min_quantity' => $validated['min_quantity'],
            'max_quantity' => max($validated['min_quantity'], $validated['max_quantity']),
            'link_instructions' => $validated['link_instructions'] ?? null,
            'status' => BotService::ACTIVE,
        ]);

        ServicePriceHistory::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'reason' => ServicePriceHistory::IMPORT,
            'old_price' => null,
            'new_price' => $service->my_price,
            'old_cost' => null,
            'new_cost' => $service->cost_price,
            'note' => 'Added by hand',
        ]);

        return Redirect::back()->with('success', 'Service added.');
    }

    public function update(Request $request, BotService $service): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'platform' => ['required', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit_label' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'my_price' => ['required', 'numeric', 'gt:0'],
            'min_quantity' => ['required', 'integer', 'min:1'],
            'max_quantity' => ['required', 'integer', 'min:1'],
            'link_instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->back(
            ServiceActions::for($request->user())->update($service, $validated)
        );
    }

    /** Status, flags and duplication — the single-service actions. */
    public function act(Request $request, BotService $service): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['status', 'flag', 'duplicate', 'price'])],
            'status' => ['required_if:action,status', Rule::in(BotService::STATUSES)],
            'flag' => ['required_if:action,flag', Rule::in(['featured', 'requires_approval'])],
            'value' => ['required_if:action,flag', 'boolean'],
            'price' => ['required_if:action,price', 'nullable', 'numeric', 'gt:0'],
        ]);

        $actions = ServiceActions::for($request->user());

        $outcome = match ($validated['action']) {
            'status' => $actions->setStatus($service, $validated['status']),
            'flag' => $actions->toggleFlag($service, $validated['flag'], (bool) $validated['value']),
            'duplicate' => $actions->duplicate($service),
            'price' => $this->setPrice($request, $service, (string) $validated['price']),
        };

        return $this->back($outcome);
    }

    public function destroy(Request $request, BotService $service): RedirectResponse
    {
        return $this->back(ServiceActions::for($request->user())->delete($service));
    }

    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(BulkServiceAction::ALL)],
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkServiceAction::MAX_SELECTION],
            'ids.*' => ['integer'],
        ]);

        $outcome = ServiceActions::for($request->user())
            ->runBulk($validated['action'], $validated['ids']);

        return Redirect::back()->with(
            $outcome['done'] === 0 ? 'error' : 'success',
            $outcome['message'],
        );
    }

    // ---- Pricing ---------------------------------------------------------

    /**
     * What a bulk price change would do — every affected line, before anything
     * is written.
     */
    public function previewPricing(Request $request): JsonResponse
    {
        $validated = $this->validatePricing($request);

        $services = ServiceActions::for($request->user())
            ->servicesByIds($validated['ids'], BulkServiceAction::MAX_PRICING_SELECTION);

        $adjustment = $this->adjustmentFrom($validated);

        return response()->json([
            'rows' => PricingEngine::for($request->user())->previewBulk($services, $adjustment),
            'describe' => $adjustment->describe(),
            'limit' => BulkServiceAction::MAX_PRICING_SELECTION,
        ]);
    }

    public function applyPricing(Request $request): RedirectResponse
    {
        $validated = $this->validatePricing($request);

        $services = ServiceActions::for($request->user())
            ->servicesByIds($validated['ids'], BulkServiceAction::MAX_PRICING_SELECTION);

        $adjustment = $this->adjustmentFrom($validated);

        $result = PricingEngine::for($request->user())->applyBulk(
            $services,
            $adjustment,
            'Bulk pricing: '.$adjustment->describe(),
        );

        $message = $result['changed'] === 0
            ? 'No prices needed changing.'
            : $result['changed'].' '.str('price')->plural($result['changed']).' updated'
                .($result['skipped'] > 0 ? ", {$result['skipped']} unchanged" : '').'.';

        return Redirect::back()->with($result['changed'] === 0 ? 'error' : 'success', $message);
    }

    // ---- Markup rules ----------------------------------------------------

    public function storeRule(Request $request): RedirectResponse
    {
        $validated = $this->validateRule($request);

        PricingRule::create([...$validated, 'tenant_id' => $request->user()->id]);

        return Redirect::back()->with('success', 'Rule saved. It applies on the next sync.');
    }

    public function updateRule(Request $request, PricingRule $rule): RedirectResponse
    {
        $rule->update($this->validateRule($request));

        return Redirect::back()->with('success', 'Rule updated.');
    }

    public function destroyRule(PricingRule $rule): RedirectResponse
    {
        $rule->delete();

        return Redirect::back()->with('success', 'Rule removed.');
    }

    /**
     * Apply the rules to the catalogue now, rather than waiting for a sync.
     *
     * Useful right after writing a rule: a reseller should be able to see what
     * it does without having to trigger a panel round-trip first.
     */
    public function applyRules(Request $request): RedirectResponse
    {
        $tenant = $request->user();
        $engine = PricingEngine::for($tenant);
        $rules = $engine->rules();

        if ($rules->isEmpty()) {
            return Redirect::back()->with('error', 'No active rules to apply.');
        }

        $changed = 0;

        BotService::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('cost_price')
            ->chunkById(200, function ($services) use ($engine, $rules, &$changed) {
                foreach ($services as $service) {
                    $price = $engine->priceFromRules($service, $rules);

                    if ($price === null || bccomp($price, (string) $service->my_price, 4) === 0) {
                        continue;
                    }

                    $engine->setPrice(
                        $service,
                        $price,
                        ServicePriceHistory::RULE,
                        'Rules applied by hand',
                    );
                    $changed++;
                }
            });

        return Redirect::back()->with(
            $changed === 0 ? 'error' : 'success',
            $changed === 0
                ? 'Your rules already match every price.'
                : $changed.' '.str('price')->plural($changed).' updated by your rules.',
        );
    }

    // ---- Panel sync and import -------------------------------------------

    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate(['panel_id' => ['required', 'integer']]);

        $panel = TenantPanel::where('tenant_id', $request->user()->id)
            ->whereKey($validated['panel_id'])
            ->first();

        if ($panel === null) {
            return Redirect::back()->with('error', 'That panel is no longer connected.');
        }

        $result = PanelSync::for($request->user())->run($panel);

        return Redirect::back()->with($result['failed'] ? 'error' : 'success', $result['message']);
    }

    /** Step 2 of the import wizard: read a panel's catalogue. */
    public function panelCatalogue(Request $request, TenantPanel $panel): JsonResponse
    {
        $result = $this->catalogue->forPanel($panel);

        return response()->json([
            'failed' => $result->failed,
            'message' => $result->message,
            'services' => $result->services,
        ]);
    }

    public function matchingIds(Request $request): JsonResponse
    {
        $filters = ServiceFilters::fromRequest($request);

        return response()->json([
            'ids' => ServiceQuery::for($request->user())
                ->matchingIds($filters, BulkServiceAction::MAX_SELECTION),
            'limit' => BulkServiceAction::MAX_SELECTION,
        ]);
    }

    public function export(Request $request)
    {
        $tenant = $request->user();
        $filters = ServiceFilters::fromRequest($request);

        return response()->streamDownload(function () use ($tenant, $filters) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID', 'Name', 'Platform', 'Category', 'Panel', 'Panel service ID',
                'Cost', 'Price', 'Profit', 'Margin %', 'Min', 'Max', 'Status',
                'Featured', 'Last synced', 'Updated',
            ]);

            ServiceQuery::for($tenant)->exportChunks($filters, function (BotService $service) use ($out) {
                fputcsv($out, [
                    $service->id,
                    $service->name,
                    $service->platform,
                    $service->category,
                    $service->panel?->name,
                    $service->provider_service_id,
                    $service->cost_price,
                    $service->my_price,
                    $service->profit(),
                    $service->margin(),
                    $service->min_quantity,
                    $service->max_quantity,
                    $service->status,
                    $service->featured ? 'yes' : 'no',
                    $service->last_synced_at?->toDateTimeString(),
                    $service->updated_at?->toDateTimeString(),
                ]);
            });

            fclose($out);
        }, 'services-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store',
        ]);
    }

    // ---- helpers ----------------------------------------------------------

    private function setPrice(Request $request, BotService $service, string $price): ActionOutcome
    {
        PricingEngine::for($request->user())->setPrice(
            $service,
            $price,
            ServicePriceHistory::MANUAL,
            'Changed from the pricing tab',
        );

        return ActionOutcome::ok('Price updated.');
    }

    private function validatePricing(Request $request): array
    {
        return $request->validate([
            'mode' => ['required', Rule::in(BulkAdjustment::MODES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'decrease' => ['boolean'],
            'min_profit' => ['nullable', 'numeric', 'min:0'],
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkServiceAction::MAX_PRICING_SELECTION],
            'ids.*' => ['integer'],
        ]);
    }

    private function adjustmentFrom(array $validated): BulkAdjustment
    {
        return BulkAdjustment::make(
            mode: $validated['mode'],
            // Fixed-point before it reaches money arithmetic; a float here
            // would round at exactly the scale that matters.
            amount: number_format((float) $validated['amount'], 4, '.', ''),
            decrease: (bool) ($validated['decrease'] ?? false),
            minProfit: isset($validated['min_profit'])
                ? number_format((float) $validated['min_profit'], 4, '.', '')
                : null,
        );
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'max:50'],
            'panel_id' => ['nullable', 'integer', Rule::exists('tenant_panels', 'id')
                ->where('tenant_id', $request->user()->id)],
            'mode' => ['required', Rule::in(PricingRule::MODES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'min_profit' => ['nullable', 'numeric', 'min:0'],
            'max_profit' => ['nullable', 'numeric', 'min:0'],
            'round_to' => ['nullable', 'numeric', 'min:0'],
            'active' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);
    }

    /** The tenant's markup rules, shaped for the rules editor. */
    private function rulesFor(Request $request): array
    {
        return PricingRule::where('tenant_id', $request->user()->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (PricingRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'platform' => $rule->platform,
                'panelId' => $rule->panel_id,
                'mode' => $rule->mode,
                'amount' => (float) $rule->amount,
                'minProfit' => $rule->min_profit === null ? null : (float) $rule->min_profit,
                'maxProfit' => $rule->max_profit === null ? null : (float) $rule->max_profit,
                'roundTo' => $rule->round_to === null ? null : (float) $rule->round_to,
                'active' => $rule->active,
                'sortOrder' => $rule->sort_order,
                'describe' => $rule->describe(),
            ])
            ->all();
    }

    private function panelIdFor(Request $request, ?int $panelId): ?int
    {
        if ($panelId === null) {
            return null;
        }

        // Scoped explicitly: a hand-edited panel id must not attach this
        // service to another reseller's panel.
        return TenantPanel::where('tenant_id', $request->user()->id)
            ->whereKey($panelId)
            ->value('id');
    }

    private function back(ActionOutcome $outcome): RedirectResponse
    {
        return Redirect::back()->with(
            $outcome->failed ? 'error' : 'success',
            $outcome->message,
        );
    }
}
