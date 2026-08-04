<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Bots\BotSettings;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * "I'll come back to this."
 *
 * Not every reseller has their panel key and a Meta number to hand the day
 * they sign up, and a wizard with no way past its first screen is a wizard
 * people abandon. Skipping moves them on without pretending anything got
 * done: the step stays incomplete everywhere completion is read, so the
 * dashboard still asks for it and going live still refuses without it.
 *
 * The test step is deliberately not skippable in the sense the others are —
 * see TestBotController::goLive(), which will not mark a shop live until the
 * bot has demonstrably answered. Skipping it leaves the reseller in sandbox,
 * which is exactly what "I have not tested it" should mean.
 */
class SkipStepController extends Controller
{
    public function store(Request $request, string $step): RedirectResponse
    {
        $target = OnboardingStep::tryFrom($step);

        if ($target === null) {
            return redirect()->route('onboarding');
        }

        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, 'order');
        $skipped = Arr::get($settings, 'shop.skipped_steps', []);

        if (! in_array($target->value, $skipped, true)) {
            $skipped[] = $target->value;
        }

        // Arr::set returns the innermost array it walked into, not the whole
        // one — so it has to be called for its effect on $settings.
        Arr::set($settings, 'shop.skipped_steps', $skipped);
        BotSettings::save($tenantId, 'order', $settings);

        $progress = OnboardingProgress::for($request->user()->fresh());

        // Nothing left to walk them through means the wizard has no next
        // screen to show, skipped or not — the dashboard carries what remains.
        if ($progress->currentStep() === null) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Skipped for now — you can finish this in Settings.');
        }

        return redirect()
            ->route('onboarding')
            ->with('status', 'Skipped for now — you can finish this in Settings.');
    }

    /**
     * Picking a step back up, which un-skips it.
     *
     * Without this a reseller who skipped everything could never return to
     * the wizard: currentStep() would walk past every screen it has.
     */
    public function destroy(Request $request, string $step): RedirectResponse
    {
        $target = OnboardingStep::tryFrom($step);

        if ($target === null) {
            return redirect()->route('onboarding');
        }

        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, 'order');

        $skipped = array_values(array_filter(
            Arr::get($settings, 'shop.skipped_steps', []),
            fn (string $stored) => $stored !== $target->value,
        ));

        Arr::set($settings, 'shop.skipped_steps', $skipped);
        BotSettings::save($tenantId, 'order', $settings);

        return redirect()->route('onboarding.step', $target->value);
    }
}
