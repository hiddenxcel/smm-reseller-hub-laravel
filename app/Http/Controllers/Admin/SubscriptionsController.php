<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Admin\SubscriptionActions;
use App\Services\Admin\SubscriptionFilters;
use App\Services\Admin\SubscriptionQuery;
use App\Services\Admin\TenantQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Who is subscribed to what, and until when.
 *
 * Sorted by ends_at ascending by default: the useful question this screen
 * answers is "who is about to lapse", not "who signed up most recently".
 */
class SubscriptionsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorise('billing.view');

        $filters = SubscriptionFilters::fromRequest($request);
        $query = SubscriptionQuery::make();

        $page = $query->paginate($filters);
        $names = $query->tenantNames(
            collect($page->items())->pluck('tenant_id')->unique()->all(),
        );

        return Inertia::render('Admin/Subscriptions/Index', [
            'subscriptions' => [
                'data' => collect($page->items())
                    ->map(fn (Subscription $subscription) => SubscriptionQuery::toRow(
                        $subscription,
                        $names[$subscription->tenant_id] ?? null,
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
            'serviceKeys' => TenantQuery::serviceKeys(),
            'canManage' => Auth::guard('superadmin')->user()->can('billing.manage'),
        ]);
    }

    /**
     * The four state changes, behind one route. Each validates its own input;
     * all of them require a reason, because each hands out or removes access
     * that somebody paid for.
     */
    public function act(Request $request, Subscription $subscription, string $action): RedirectResponse
    {
        $this->authorise('billing.manage');

        $actions = SubscriptionActions::for($subscription);

        return match ($action) {
            'extend' => $this->extend($request, $actions),
            'cancel' => $this->cancel($request, $actions),
            'reinstate' => $this->reinstate($request, $actions),
            'auto-renew' => $this->autoRenew($request, $actions),
        };
    }

    private function extend(Request $request, SubscriptionActions $actions): RedirectResponse
    {
        $validated = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $actions->extend((int) $validated['months'], $validated['reason']);

        return back()->with('success', "Extended by {$validated['months']} month(s).");
    }

    private function cancel(Request $request, SubscriptionActions $actions): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $actions->cancel($validated['reason']);

        return back()->with('success', 'Subscription cancelled. Access ends now.');
    }

    private function reinstate(Request $request, SubscriptionActions $actions): RedirectResponse
    {
        $validated = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $actions->reinstate((int) $validated['months'], $validated['reason']);

        return back()->with('success', "Reinstated for {$validated['months']} month(s).");
    }

    private function autoRenew(Request $request, SubscriptionActions $actions): RedirectResponse
    {
        $validated = $request->validate([
            'on' => ['required', Rule::in(['0', '1', 0, 1, true, false])],
        ]);

        $on = filter_var($validated['on'], FILTER_VALIDATE_BOOLEAN);

        $actions->setAutoRenew($on);

        return back()->with('success', $on ? 'Auto-renew on.' : 'Auto-renew off.');
    }

    private function authorise(string $ability): void
    {
        if (! Auth::guard('superadmin')->user()->can($ability)) {
            throw new AccessDeniedHttpException("Your role cannot {$ability}.");
        }
    }
}
