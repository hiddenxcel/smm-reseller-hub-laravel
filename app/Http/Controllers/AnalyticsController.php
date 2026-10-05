<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\AnalyticsReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The longer view: trends, timing and where the money comes from.
 *
 * The window is a query-string choice (7, 30 or 90 days) rather than state, so
 * a reseller can bookmark or send "last quarter" as a link. Anything else falls
 * back to 30 days instead of erroring — a hand-edited URL should still load.
 */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $report = AnalyticsReport::for($request->user(), (int) $request->query('range', AnalyticsReport::DEFAULT_RANGE));

        return Inertia::render('Analytics', [
            'range' => $report->days(),
            'ranges' => AnalyticsReport::RANGES,
            'summary' => $report->summary(),
            'trend' => $report->trend(),
            'statusMix' => $report->statusMix(),
            'topServices' => $report->topServices(),
            'timing' => $report->timing(),
            'gateways' => $report->gateways(),
            'topCustomers' => $report->topCustomers(),
            'messages' => $report->messages(),
        ]);
    }
}
