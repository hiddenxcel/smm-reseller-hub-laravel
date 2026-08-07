<?php

namespace App\Http\Controllers;

use App\Enums\ServiceKey;
use App\Models\BotService;
use App\Models\Plan;
use App\Models\TenantPanel;
use App\Services\Panel\PanelDetector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The panels a reseller buys from.
 *
 * Onboarding connects the first one and moves on; this is where the rest are
 * managed — added, re-checked, removed. Panels belong to the order bot because
 * the order bot is what spends on them.
 *
 * The API key is write-only. It goes in encrypted and is never sent back, so a
 * reseller re-typing one is replacing it, and leaving the field blank keeps
 * what is already stored.
 */
class OrderBotProvidersController extends Controller
{
    public function __construct(private PanelDetector $detector) {}

    public function index(Request $request): Response
    {
        $tenantId = (int) $request->user()->id;

        return Inertia::render('OrderBot/Providers', [
            'providers' => $this->providers($tenantId),
            'limit' => $this->limit($tenantId),
        ]);
    }

    /**
     * How many panels this plan allows, and how many are in use.
     *
     * Sent to the page rather than only enforced on save: a form that is
     * visibly closed beats one that takes a URL, a key, and a wait before
     * saying no.
     */
    private function limit(int $tenantId): array
    {
        // No plan row means no configured allowance, and the safe reading of
        // that is one panel rather than unlimited — a missing plan should not
        // hand out more than the paid ones do.
        $max = (int) (Plan::forService(ServiceKey::OrderBot)?->max_panels ?? 1);
        $used = TenantPanel::withoutTenantScope()->where('tenant_id', $tenantId)->count();

        return [
            'max' => $max,
            'used' => $used,
            'reached' => $used >= $max,
        ];
    }

    /**
     * @return array<int, array>
     */
    private function providers(int $tenantId): array
    {
        // One grouped count rather than a query per panel — the service count
        // is shown on every card, and it is what makes deleting one dangerous.
        $serviceCounts = BotService::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->selectRaw('panel_id, COUNT(*) AS total')
            ->groupBy('panel_id')
            ->pluck('total', 'panel_id');

        return TenantPanel::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get()
            ->map(fn (TenantPanel $panel) => [
                'id' => $panel->id,
                'name' => $panel->name,
                'panelType' => $panel->panel_type,
                'apiUrl' => $panel->api_url,
                'authMethod' => $panel->auth_method,
                'status' => $panel->status,
                'balance' => $panel->last_balance === null ? null : (float) $panel->last_balance,
                'currency' => $panel->balance_currency,
                // The count stored on the panel is what detection saw; this is
                // what the reseller actually imported, which is the number that
                // matters when deleting.
                'importedServices' => (int) ($serviceCounts[$panel->id] ?? 0),
                'catalogueSize' => $panel->services_count,
                'lastCheckedAt' => $panel->last_checked_at?->toIso8601String(),
            ])
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'api_url' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'max:255'],
        ]);

        $tenantId = (int) $request->user()->id;

        // Checked again here, not only in the UI: the page's copy of the count
        // is a moment old, and this is the one that has to hold.
        if ($this->limit($tenantId)['reached']) {
            throw ValidationException::withMessages([
                'api_url' => 'Your plan does not allow another panel.',
            ]);
        }

        $detection = $this->detector->detect($validated['api_url'], $validated['api_key']);

        if (! $detection->connected) {
            // Reported against api_url so the message lands next to the field
            // the reseller most likely got wrong.
            throw ValidationException::withMessages([
                'api_url' => $detection->message,
            ]);
        }

        TenantPanel::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'api_url' => $detection->apiUrl,
            ],
            [
                'name' => $validated['name'],
                'panel_type' => $detection->panelType(),
                'api_key_enc' => $validated['api_key'],
                'api_version' => 'v2',
                'auth_method' => $detection->authMethod,
                'last_checked_at' => now(),
                'last_balance' => $detection->balance,
                'balance_currency' => $detection->currency,
                'services_count' => $detection->servicesCount,
                'status' => 'active',
            ],
        );

        return back()->with('success', "Connected to {$validated['name']}.");
    }

    /**
     * Ask the panel where it stands now.
     *
     * A stored balance is only true for the moment it was read, and a panel
     * that has since gone down or had its key revoked looks perfectly healthy
     * until someone asks it — which is what this does.
     */
    public function refresh(Request $request, TenantPanel $panel): RedirectResponse
    {
        $this->authorizePanel($request, $panel);

        $detection = $this->detector->detect($panel->api_url, $panel->api_key_enc);

        if (! $detection->connected) {
            // Marked down rather than deleted: the reseller decides whether a
            // panel that failed once is worth removing.
            $panel->update([
                'status' => 'error',
                'last_checked_at' => now(),
            ]);

            return back()->with('error', "{$panel->name} did not answer: {$detection->message}");
        }

        $panel->update([
            'status' => 'active',
            'auth_method' => $detection->authMethod,
            'last_checked_at' => now(),
            'last_balance' => $detection->balance,
            'balance_currency' => $detection->currency,
            'services_count' => $detection->servicesCount,
        ]);

        return back()->with('success', "{$panel->name} is up to date.");
    }

    /**
     * Remove a panel.
     *
     * Its imported services are left behind on purpose. Deleting them would
     * throw away the reseller's own pricing and every order's link back to
     * what was sold; instead the services are paused, so the bot stops
     * offering things it can no longer buy while the record survives.
     */
    public function destroy(Request $request, TenantPanel $panel): RedirectResponse
    {
        $this->authorizePanel($request, $panel);

        $paused = BotService::withoutTenantScope()
            ->where('tenant_id', $panel->tenant_id)
            ->where('panel_id', $panel->id)
            ->where('status', '!=', 'hidden')
            ->update(['status' => 'hidden']);

        $name = $panel->name;
        $panel->delete();

        $message = $paused > 0
            ? "{$name} removed. {$paused} of its services were hidden."
            : "{$name} removed.";

        return back()->with('success', $message);
    }

    /**
     * Route model binding crosses the tenant scope, so ownership is checked
     * here — without it a panel id from another reseller would resolve.
     */
    private function authorizePanel(Request $request, TenantPanel $panel): void
    {
        abort_unless((int) $panel->tenant_id === (int) $request->user()->id, 404);
    }
}
