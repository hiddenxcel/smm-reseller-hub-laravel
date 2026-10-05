<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Bots\BotSettings;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\TestBotStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * The last step: message your own bot, watch the reply arrive, then go live.
 *
 * The reseller's test number is stored in bot settings rather than as its own
 * table because the bot already reads it from there — a number added here is
 * the same number the sandbox gate lets through.
 */
class TestBotController extends Controller
{
    /** Register a number the reseller will message the bot from. */
    public function storeNumber(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $digits = preg_replace('/\D/', '', $validated['phone']) ?? '';

        // A number with no digits would be stored and then never match an
        // inbound message, leaving the reseller waiting on a reply forever.
        if ($digits === '') {
            throw ValidationException::withMessages([
                'phone' => 'That does not look like a phone number.',
            ]);
        }

        $tenantId = $request->user()->id;
        $settings = BotSettings::for($tenantId, 'order');
        $numbers = Arr::get($settings, 'shop.test_numbers', []);

        if (! in_array($digits, $numbers, true)) {
            $numbers[] = $digits;
        }

        // Arr::set returns the innermost array it walked into, not the whole
        // one — so it has to be called for its effect on $settings.
        Arr::set($settings, 'shop.test_numbers', $numbers);
        BotSettings::save($tenantId, 'order', $settings);

        return redirect()
            ->route('onboarding.step', 'test')
            ->with('status', 'Test number added — message your bot now.');
    }

    public function destroyNumber(Request $request, string $phone): RedirectResponse
    {
        $tenantId = $request->user()->id;
        $settings = BotSettings::for($tenantId, 'order');

        $numbers = array_values(array_filter(
            Arr::get($settings, 'shop.test_numbers', []),
            fn (string $stored) => $stored !== $phone,
        ));

        Arr::set($settings, 'shop.test_numbers', $numbers);
        BotSettings::save($tenantId, 'order', $settings);

        return redirect()->route('onboarding.step', 'test');
    }

    /**
     * Mark the test as passed and finish the wizard.
     *
     * Nothing but the reseller can say the test worked — we cannot tell a real
     * conversation from a rehearsal — but we can refuse to accept it when the
     * bot has demonstrably never been reached.
     */
    public function goLive(Request $request): RedirectResponse
    {
        $tenant = $request->user();

        $settings = BotSettings::for($tenant->id, 'order');

        // Either way of seeing it work counts: a real message on WhatsApp, or a
        // test order in the simulator against the reseller's own services.
        if (! TestBotStatus::for($tenant->id)->botHasReplied()
            && ! Arr::get($settings, 'shop.sim_tested', false)) {
            throw ValidationException::withMessages([
                'go_live' => 'Try your bot first — chat with it on the left, or message it on WhatsApp.',
            ]);
        }

        Arr::set($settings, 'shop.bot_tested', true);
        BotSettings::save($tenant->id, 'order', $settings);

        $progress = OnboardingProgress::for($tenant->fresh());

        return redirect()
            ->route($progress->currentStep() === null ? 'dashboard' : 'onboarding')
            ->with('status', 'Your shop is live.');
    }
}
