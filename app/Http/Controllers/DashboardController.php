<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardMetrics;
use App\Services\Dashboard\ProfitReport;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reseller's control centre — what happened, what needs attention, and
 * where to go next.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $tenant = $request->user();
        $metrics = DashboardMetrics::for($tenant);
        $profit = ProfitReport::for($tenant);
        $progress = OnboardingProgress::for($tenant);

        return Inertia::render('Dashboard', [
            'businessName' => $tenant->business_name,
            'kpis' => $metrics->kpis(),
            'botStatus' => $metrics->botStatus(),
            'trend' => $metrics->trend(),
            'statusMix' => $metrics->orderStatusMix(),
            'topServices' => $metrics->topServices(),
            // Turnover is what the KPI row shows; this is what is left of it.
            'profit' => [
                'summary' => $profit->summary(),
                'byService' => $profit->byService(),
                'underwater' => $profit->underwater(),
            ],
            'panels' => $metrics->panels(),
            'recentOrders' => $metrics->recentOrders(),
            'recentTickets' => $metrics->recentTickets(),
            'openTickets' => $metrics->openTicketCount(),
            // Setup stays visible after the wizard: payments is optional, so a
            // reseller can be live with it still undone, and ought to see that.
            'setup' => [
                'steps' => $progress->toArray(),
                'completed' => $progress->completedCount(),
                'readyToGoLive' => $progress->isReadyToGoLive(),
            ],
        ]);
    }
}
