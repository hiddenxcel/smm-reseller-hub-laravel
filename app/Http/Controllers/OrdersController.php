<?php

namespace App\Http\Controllers;

use App\Models\BotOrder;
use App\Services\Orders\BulkOrderAction;
use App\Services\Orders\OrderActions;
use App\Services\Orders\OrderFilters;
use App\Services\Orders\OrderQuery;
use App\Services\Orders\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The orders list — the screen a reseller lives on.
 *
 * Filtering, sorting and paging all happen in the URL rather than in React
 * state, so a filtered view can be bookmarked, shared with a colleague, and
 * survives a refresh. Inertia partial reloads keep that from costing a full
 * page render on every keystroke.
 */
class OrdersController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user();
        $filters = OrderFilters::fromRequest($request);
        $query = OrderQuery::for($tenant);

        $page = $query->paginate($filters);

        return Inertia::render('Orders/Index', [
            'orders' => [
                'data' => collect($page->items())
                    ->map(fn (BotOrder $order) => OrderQuery::toRow($order))
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
            // Deferred: the tab counts are four aggregate queries, and the
            // table is far more useful on screen a moment before they land
            // than it is held back waiting for them.
            'tabCounts' => Inertia::defer(fn () => $query->tabCounts($filters)),
            'summary' => Inertia::defer(fn () => $query->summary($filters)),
            'panels' => $query->panelOptions(),
            'pageSizes' => OrderQuery::PAGE_SIZES,
            'statusLabels' => [
                OrderStatus::COMPLETED => 'Completed',
                OrderStatus::PROCESSING => 'In progress',
                OrderStatus::PENDING => 'Pending',
                OrderStatus::FAILED => 'Failed',
            ],
            'bulkLimits' => [
                'default' => BulkOrderAction::MAX_SELECTION,
                'panel' => BulkOrderAction::MAX_PANEL_SELECTION,
            ],
        ]);
    }

    /**
     * One action on one order.
     *
     * The order is resolved through the tenant scope (route model binding on a
     * scoped model), so an id belonging to another reseller 404s here rather
     * than being caught by a check further in.
     */
    public function act(Request $request, BotOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(OrderActions::ALL)],
            'status' => ['required_if:action,'.OrderActions::MARK, Rule::in(array_keys(OrderStatus::GROUPS))],
        ]);

        $action = $validated['action'];

        if (! OrderActions::isAvailable($order, $action)) {
            return Redirect::back()->with('error', 'That action is not available for this order.');
        }

        $result = match ($action) {
            OrderActions::RETRY => OrderActions::retry($order),
            OrderActions::REFILL => OrderActions::refill($order),
            OrderActions::CANCEL => OrderActions::cancel($order),
            OrderActions::MARK => OrderActions::markStatus($order, $validated['status']),
        };

        return Redirect::back()->with($result->failed ? 'error' : 'success', $result->message);
    }

    /** The same actions, across a selection. */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(OrderActions::ALL)],
            'status' => ['required_if:action,'.OrderActions::MARK, Rule::in(array_keys(OrderStatus::GROUPS))],
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkOrderAction::MAX_SELECTION],
            'ids.*' => ['integer'],
        ]);

        $outcome = BulkOrderAction::for($request->user())->run(
            $validated['action'],
            $validated['ids'],
            $validated['status'] ?? null,
        );

        return Redirect::back()->with(
            $outcome['done'] === 0 && $outcome['failed'] > 0 ? 'error' : 'success',
            $outcome['message'],
        );
    }

    /**
     * Every id the current filter matches, for "select all N".
     *
     * A plain JSON endpoint rather than an Inertia response: the page is not
     * navigating, it is topping up a selection the user already made.
     */
    public function matchingIds(Request $request)
    {
        $filters = OrderFilters::fromRequest($request);

        return response()->json([
            'ids' => OrderQuery::for($request->user())
                ->matchingIds($filters, BulkOrderAction::MAX_SELECTION),
            'limit' => BulkOrderAction::MAX_SELECTION,
        ]);
    }

    /** CSV of the current filter, streamed so a large export never buffers. */
    public function export(Request $request)
    {
        $tenant = $request->user();
        $filters = OrderFilters::fromRequest($request);
        $filename = 'orders-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($tenant, $filters) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Order', 'Panel order', 'Date', 'Customer', 'Service',
                'Link', 'Quantity', 'Amount', 'Charge', 'Payment', 'Paid from',
                'Status', 'Panel', 'Error',
            ]);

            OrderQuery::for($tenant)
                ->exportChunks($filters, function (BotOrder $order) use ($out) {
                    fputcsv($out, [
                        $order->id,
                        $order->provider_order_id,
                        $order->created_at?->toDateTimeString(),
                        $order->customer_phone,
                        $order->service_name,
                        $order->link,
                        $order->quantity,
                        $order->amount,
                        $order->charge,
                        $order->payment_status,
                        $order->paid_from,
                        $order->status,
                        $order->panel?->name,
                        $order->order_error,
                    ]);
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store',
        ]);
    }
}
