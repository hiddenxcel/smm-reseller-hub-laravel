<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\CatalogueOverview;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the platform's resellers are selling, reported rather than managed.
 *
 * There is no global catalogue to edit — every service row belongs to one
 * reseller, priced by them, from their own panel. Editing those from here would
 * change what someone else's customers pay without that reseller knowing, so
 * this screen reads and never writes.
 */
class CatalogueController extends AdminController
{
    public function index(): Response
    {
        $this->authorise('tenants.view');

        $overview = CatalogueOverview::make();

        return Inertia::render('Admin/Catalogue/Index', [
            'kpis' => $overview->kpis(),
            // Each of these is an aggregate over every reseller's catalogue, so
            // the KPI row lands first and the rest follows.
            'platforms' => Inertia::defer(fn () => $overview->platforms()),
            'panels' => Inertia::defer(fn () => $overview->panels()),
            'autoPaused' => Inertia::defer(fn () => $overview->autoPaused()),
            'topSelling' => Inertia::defer(fn () => $overview->topSelling()),
            'biggestCatalogues' => Inertia::defer(fn () => $overview->biggestCatalogues()),
            'pricing' => Inertia::defer(fn () => $overview->pricing()),
        ]);
    }
}
