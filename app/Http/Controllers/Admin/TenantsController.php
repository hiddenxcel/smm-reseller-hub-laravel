<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\TenantActions;
use App\Services\Admin\TenantFilters;
use App\Services\Admin\TenantProfile;
use App\Services\Admin\TenantQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Every reseller on the platform, and what can be done about them.
 *
 * The list state lives in the query string, like the reseller-facing tables, so
 * a filtered view is a URL an admin can paste into a ticket.
 */
class TenantsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorise('tenants.view');

        $filters = TenantFilters::fromRequest($request);
        $query = TenantQuery::make();

        $page = $query->paginate($filters);

        $ids = collect($page->items())->pluck('id')->all();
        $services = $query->servicesFor($ids);
        $totals = $query->totalsFor($ids);

        return Inertia::render('Admin/Tenants/Index', [
            'tenants' => [
                'data' => collect($page->items())
                    ->map(fn (Tenant $tenant) => TenantQuery::toRow(
                        $tenant,
                        $services[$tenant->id] ?? [],
                        $totals[$tenant->id] ?? [],
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
            'tabCounts' => Inertia::defer(fn () => $query->tabCounts($filters)),
            'pageSizes' => TenantQuery::PAGE_SIZES,
            'serviceKeys' => TenantQuery::serviceKeys(),
            'can' => $this->abilities(),
        ]);
    }

    /**
     * One reseller. The overview arrives with the page; every other tab is
     * fetched over JSON when it is opened, so landing here costs one set of
     * queries rather than eight.
     */
    public function show(Request $request, int $tenant, string $tab = 'overview'): Response|JsonResponse
    {
        $this->authorise('tenants.view');

        $model = Tenant::findOrFail($tenant);
        $profile = TenantProfile::for($model);

        if ($request->wantsJson()) {
            return response()->json(match ($tab) {
                'subscriptions' => ['subscriptions' => $profile->subscriptions()],
                'orders' => ['orders' => $profile->orders()],
                'customers' => ['customers' => $profile->customers()],
                'payments' => ['payments' => $profile->payments()],
                'panels' => $profile->panels(),
                'numbers' => $profile->numbers(),
                'activity' => ['activity' => $profile->activity()],
                default => ['overview' => $profile->overview()],
            });
        }

        return Inertia::render('Admin/Tenants/Show', [
            'tenant' => $profile->overview(),
            'tab' => $tab,
            'can' => $this->abilities(),
        ]);
    }

    public function update(Request $request, int $tenant): RedirectResponse
    {
        $this->authorise('tenants.edit');

        $model = Tenant::findOrFail($tenant);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('tenants', 'email')->ignore($model->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'lang' => ['required', 'string', Rule::in(['en', 'fr', 'sw'])],
        ]);

        TenantActions::for($model)->updateDetails($validated);

        return back()->with('success', 'Reseller updated.');
    }

    /**
     * The four state changes, behind one route.
     *
     * Grouped because they share a subject and an audit shape; each still
     * validates its own input and checks its own permission.
     */
    public function act(Request $request, int $tenant, string $action): RedirectResponse
    {
        $model = Tenant::findOrFail($tenant);
        $actions = TenantActions::for($model);

        return match ($action) {
            'suspend' => $this->suspend($request, $actions, $model),
            'activate' => $this->activate($actions, $model),
            'credit' => $this->credit($request, $actions),
            'password' => $this->password($request, $actions),
        };
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorise('tenants.view');

        $filters = TenantFilters::fromRequest($request);
        $query = TenantQuery::make();

        $page = $query->paginate($filters);

        AdminAudit::record('tenants.export', [
            'filters' => $filters->toArray(),
            'rows' => $page->total(),
        ]);

        $columns = ['id', 'business_name', 'email', 'phone', 'status', 'referral_credit', 'first_payment_done', 'created_at'];

        return response()->streamDownload(function () use ($filters, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            // Chunked so an export of every reseller does not hold the whole
            // table in memory.
            Tenant::query()
                ->when($filters->status !== null, fn ($q) => $q->where('status', $filters->status))
                ->orderBy('id')
                ->chunk(500, function ($tenants) use ($handle, $columns) {
                    foreach ($tenants as $tenant) {
                        fputcsv($handle, array_map(
                            fn (string $column) => $tenant->{$column},
                            $columns,
                        ));
                    }
                });

            fclose($handle);
        }, 'resellers-'.now()->toDateString().'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    // ---- internals -------------------------------------------------------

    private function suspend(Request $request, TenantActions $actions, Tenant $tenant): RedirectResponse
    {
        $this->authorise('tenants.suspend');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $actions->suspend($validated['reason'] ?? null);

        return back()->with('success', "{$tenant->business_name} suspended.");
    }

    private function activate(TenantActions $actions, Tenant $tenant): RedirectResponse
    {
        $this->authorise('tenants.suspend');

        $actions->activate();

        return back()->with('success', "{$tenant->business_name} reactivated.");
    }

    private function credit(Request $request, TenantActions $actions): RedirectResponse
    {
        $this->authorise('billing.manage');

        $validated = $request->validate([
            'delta' => ['required', 'numeric', 'between:-100000,100000', 'not_in:0'],
            // Moving someone's money without saying why is exactly what the
            // audit trail exists to prevent, so the reason is required.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $balance = $actions->adjustCredit((float) $validated['delta'], $validated['reason']);

        return back()->with('success', 'Credit adjusted. New balance: '.number_format($balance, 2));
    }

    private function password(Request $request, TenantActions $actions): RedirectResponse
    {
        $this->authorise('tenants.edit');

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $actions->resetPassword($validated['password']);

        return back()->with('success', 'Password reset. Send it to the reseller over a channel they already use.');
    }

    private function authorise(string $ability): void
    {
        if (! Auth::guard('superadmin')->user()->can($ability)) {
            throw new AccessDeniedHttpException("Your role cannot {$ability}.");
        }
    }

    /** What this admin may do, so the screen can hide what it cannot offer. */
    private function abilities(): array
    {
        $admin = Auth::guard('superadmin')->user();

        return [
            'edit' => $admin->can('tenants.edit'),
            'suspend' => $admin->can('tenants.suspend'),
            'impersonate' => $admin->can('tenants.impersonate'),
            'credit' => $admin->can('billing.manage'),
        ];
    }
}
