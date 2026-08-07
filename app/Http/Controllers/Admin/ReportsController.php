<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\Reports;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends AdminController
{
    public function index(): Response
    {
        $this->authorise('billing.view');

        $reports = Reports::make();

        return Inertia::render('Admin/Reports/Index', [
            // MRR and growth are the two figures someone opens this page for,
            // so they arrive with it rather than a moment later.
            'mrr' => $reports->mrr(),
            'growth' => $reports->growth(),
            'monthly' => Inertia::defer(fn () => $reports->monthly()),
            'conversion' => Inertia::defer(fn () => $reports->conversion()),
            'byGateway' => Inertia::defer(fn () => $reports->byGateway()),
            'topResellers' => Inertia::defer(fn () => $reports->topResellers()),
            'serviceMix' => Inertia::defer(fn () => $reports->serviceMix()),
        ]);
    }
}
