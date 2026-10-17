<?php

namespace App\Http\Controllers;

use App\Models\CatalogueChange;
use App\Services\Catalogue\CatalogueChanges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the panels changed since the reseller last looked: costs that moved and
 * services that disappeared, each to allow or dismiss, plus the switch that
 * lets the sync do it unasked.
 */
class CatalogueChangesController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user();
        $changes = CatalogueChanges::for($tenant);

        return Inertia::render('Services/Changes', [
            'autoSync' => (bool) $tenant->auto_catalogue_sync,
            'pending' => $changes->pending()->map(fn (CatalogueChange $c) => $this->row($c))->values()->all(),
            'recent' => CatalogueChange::query()
                ->whereIn('status', [CatalogueChange::APPLIED, CatalogueChange::DISMISSED])
                ->with('panel:id,name')
                ->latest('resolved_at')
                ->limit(25)
                ->get()
                ->map(fn (CatalogueChange $c) => $this->row($c))
                ->values()
                ->all(),
        ]);
    }

    public function approve(Request $request, CatalogueChange $change): RedirectResponse
    {
        CatalogueChanges::for($request->user())->approve($change);

        return Redirect::back()->with('success', 'Change allowed.');
    }

    public function dismiss(Request $request, CatalogueChange $change): RedirectResponse
    {
        CatalogueChanges::for($request->user())->dismiss($change);

        return Redirect::back()->with('success', 'Change dismissed.');
    }

    public function resolveAll(Request $request): RedirectResponse
    {
        $validated = $request->validate(['action' => ['required', 'in:approve,dismiss']]);
        $approve = $validated['action'] === 'approve';

        $done = CatalogueChanges::for($request->user())->resolveAll($approve);

        return Redirect::back()->with(
            'success',
            $done.' '.str('change')->plural($done).($approve ? ' allowed.' : ' dismissed.'),
        );
    }

    /** Turning it on also answers everything already waiting, in the reseller's favour. */
    public function toggleAuto(Request $request): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $tenant = $request->user();

        $tenant->forceFill(['auto_catalogue_sync' => (bool) $validated['enabled']])->save();

        if ($tenant->auto_catalogue_sync) {
            CatalogueChanges::for($tenant)->resolveAll(true);
        }

        return Redirect::back()->with('success', $tenant->auto_catalogue_sync
            ? 'Auto-sync is on. Prices and removed services now follow your providers.'
            : 'Auto-sync is off. You will approve each change.');
    }

    /** @return array<string, mixed> */
    private function row(CatalogueChange $c): array
    {
        $price = $c->proposed_price ?? $c->old_price;

        return [
            'id' => $c->id,
            'kind' => $c->kind,
            'status' => $c->status,
            'serviceId' => $c->service_id,
            'name' => $c->service_name,
            'panel' => $c->panel?->name,
            'oldCost' => $c->old_cost === null ? null : (float) $c->old_cost,
            'newCost' => $c->new_cost === null ? null : (float) $c->new_cost,
            'oldPrice' => $c->old_price === null ? null : (float) $c->old_price,
            'newPrice' => $price === null ? null : (float) $price,
            'underwater' => $c->new_cost !== null && $price !== null
                && bccomp((string) $price, (string) $c->new_cost, 4) < 0,
            'note' => $c->note,
            'at' => ($c->resolved_at ?? $c->created_at)?->toIso8601String(),
        ];
    }
}
