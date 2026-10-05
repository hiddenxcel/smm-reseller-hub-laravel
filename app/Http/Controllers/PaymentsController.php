<?php

namespace App\Http\Controllers;

use App\Models\BotPayment;
use App\Services\Bots\BotSettings;
use App\Services\Payments\PaymentsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the reseller's customers have paid them.
 *
 * Filtering and paging live in the query string, so a filtered view is a link
 * that can be sent to a colleague and survives a refresh. Money the reseller
 * pays the platform is on Billing, not here.
 */
class PaymentsController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user();
        $query = PaymentsQuery::for($tenant, $request->query());

        $page = $query->paginate((int) $request->query('page', 1));

        return Inertia::render('OrderBot/Payments', [
            'payments' => collect($page->items())
                ->map(fn (BotPayment $payment) => PaymentsQuery::toRow($payment))
                ->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'filters' => $query->filters(),
            'isFiltered' => $query->isFiltered(),
            'ranges' => PaymentsQuery::RANGES,
            'summary' => $query->summary(),
            'tabCounts' => $query->tabCounts(),
            'gateways' => $query->gatewayOptions(),
            // Amounts are in the shop's own currency: what the customer was
            // charged locally is settled into it before it reaches this list.
            'currency' => (string) Arr::get(BotSettings::for((int) $tenant->id, 'order'), 'shop.currency', 'USD'),
        ]);
    }
}
