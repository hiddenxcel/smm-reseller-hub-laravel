<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\PlatformMetrics;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $metrics = PlatformMetrics::make();

        return Inertia::render('Admin/Dashboard', [
            'kpis' => $metrics->kpis(),
            'alerts' => $metrics->alerts(),
            // Deferred: each is several aggregates over every tenant's data, and
            // the KPI row is more useful on screen a moment before they land.
            'trend' => Inertia::defer(fn () => $metrics->trend()),
            'serviceMix' => Inertia::defer(fn () => $metrics->serviceMix()),
            'topResellers' => Inertia::defer(fn () => $metrics->topResellers()),
            'recentSignups' => Inertia::defer(fn () => $metrics->recentSignups()),
        ]);
    }
}
