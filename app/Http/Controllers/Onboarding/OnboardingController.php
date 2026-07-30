<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\TenantPanel;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
use App\Services\Panel\ServiceCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The setup wizard a reseller lands in after signing up.
 *
 * Dropping someone into an empty dashboard is how they leave without ever
 * connecting anything; this walks them to a working shop instead.
 */
class OnboardingController extends Controller
{
    public function __construct(private ServiceCatalogue $catalogue) {}

    /** Send the reseller to whichever step they still need. */
    public function index(Request $request): RedirectResponse
    {
        $progress = OnboardingProgress::for($request->user());
        $step = $progress->currentStep();

        if ($step === null) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('onboarding.step', $step->value);
    }

    public function show(Request $request, string $step): Response|RedirectResponse
    {
        $current = OnboardingStep::tryFrom($step);

        if ($current === null) {
            return redirect()->route('onboarding');
        }

        $progress = OnboardingProgress::for($request->user());

        $shared = [
            'step' => $current->value,
            'steps' => $progress->toArray(),
            'completed' => $progress->completedCount(),
            'readyToGoLive' => $progress->isReadyToGoLive(),
        ];

        return match ($current) {
            OnboardingStep::ConnectPanel => Inertia::render('Onboarding/ConnectPanel', $shared),

            OnboardingStep::ImportServices => $this->importServices($request, $shared),

            // Steps without a screen yet say so plainly, rather than showing a
            // form that quietly does nothing.
            default => Inertia::render('Onboarding/ComingSoonStep', [
                ...$shared,
                'title' => $current->title(),
                'description' => $current->description(),
            ]),
        };
    }

    /**
     * Reading the catalogue means calling the panel, which can be slow or
     * down — so the page renders either way, and says what went wrong.
     */
    private function importServices(Request $request, array $shared): Response|RedirectResponse
    {
        $panel = TenantPanel::where('tenant_id', $request->user()->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        // No panel means the previous step is not really done; send them back.
        if ($panel === null) {
            return redirect()->route('onboarding');
        }

        $catalogue = $this->catalogue->forPanel($panel);

        return Inertia::render('Onboarding/ImportServices', [
            ...$shared,
            'panel' => [
                'id' => $panel->id,
                'name' => $panel->name,
            ],
            'services' => $catalogue->services,
            'catalogueError' => $catalogue->message,
        ]);
    }
}
