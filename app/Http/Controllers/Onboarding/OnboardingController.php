<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
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

        // Steps without a screen yet say so plainly, rather than showing a
        // form that quietly does nothing.
        if ($current !== OnboardingStep::ConnectPanel) {
            return Inertia::render('Onboarding/ComingSoonStep', [
                ...$shared,
                'title' => $current->title(),
                'description' => $current->description(),
            ]);
        }

        return Inertia::render('Onboarding/ConnectPanel', $shared);
    }
}
