<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Services\Admin\PaymentActions;
use App\Services\Admin\PaymentFilters;
use App\Services\Admin\PaymentQuery;
use App\Services\Billing\ActivatePurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * What resellers have paid the platform, and what to do when a payment sticks.
 *
 * Every write here goes through PaymentActions rather than touching the model,
 * so the activation path and the audit line cannot be skipped by a future
 * caller in a hurry.
 */
class PaymentsController extends Controller
{
    public function __construct(private ActivatePurchase $activate) {}

    public function index(Request $request): Response
    {
        $this->authorise('billing.view');

        $filters = PaymentFilters::fromRequest($request);
        $query = PaymentQuery::make();

        $page = $query->paginate($filters);
        $names = $query->tenantNames(
            collect($page->items())->pluck('tenant_id')->unique()->all(),
        );

        return Inertia::render('Admin/Payments/Index', [
            'payments' => [
                'data' => collect($page->items())
                    ->map(fn (SubscriptionPayment $payment) => PaymentQuery::toRow(
                        $payment,
                        $names[$payment->tenant_id] ?? null,
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
            'totals' => Inertia::defer(fn () => $query->totals($filters)),
            'gateways' => Inertia::defer(fn () => $query->gatewaysSeen()),
            'canManage' => Auth::guard('superadmin')->user()->can('billing.manage'),
        ]);
    }

    /**
     * One payment in full, including the gateway's own response.
     *
     * JSON rather than a page: the list opens this in a panel over itself, and
     * the raw response is the whole reason for looking.
     */
    public function show(SubscriptionPayment $payment): JsonResponse
    {
        $this->authorise('billing.view');

        $name = PaymentQuery::make()->tenantNames([$payment->tenant_id]);

        return response()->json([
            'payment' => PaymentQuery::toDetail(
                $payment,
                $name[$payment->tenant_id] ?? null,
            ),
        ]);
    }

    public function act(Request $request, SubscriptionPayment $payment, string $action): RedirectResponse
    {
        $this->authorise('billing.manage');

        $validated = $request->validate([
            // Confirming a payment grants a subscription without a gateway
            // signature to justify it, so the stated reason is the only
            // evidence of why. Required for all three.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $actions = PaymentActions::for($payment, $this->activate);

        return match ($action) {
            'confirm' => $this->confirm($actions, $validated['reason']),
            'fail' => $this->fail($actions, $validated['reason']),
            'reapply' => $this->reapply($actions, $validated['reason']),
        };
    }

    private function confirm(PaymentActions $actions, string $reason): RedirectResponse
    {
        if (! $actions->confirm($reason)) {
            // A webhook landed while the admin was deciding. Nothing is wrong,
            // but claiming to have granted it would be a lie.
            return back()->with('error', 'Already applied — a webhook confirmed this first.');
        }

        return back()->with('success', 'Payment confirmed and the purchase applied.');
    }

    private function fail(PaymentActions $actions, string $reason): RedirectResponse
    {
        if (! $actions->markFailed($reason)) {
            return back()->with('error', 'Only a pending payment can be marked failed.');
        }

        return back()->with('success', 'Payment marked failed. No money was moved.');
    }

    private function reapply(PaymentActions $actions, string $reason): RedirectResponse
    {
        if (! $actions->reapply($reason)) {
            return back()->with('error', 'Only a successful payment can be re-applied.');
        }

        return back()->with('success', 'Purchase re-applied.');
    }

    private function authorise(string $ability): void
    {
        if (! Auth::guard('superadmin')->user()->can($ability)) {
            throw new AccessDeniedHttpException("Your role cannot {$ability}.");
        }
    }
}
