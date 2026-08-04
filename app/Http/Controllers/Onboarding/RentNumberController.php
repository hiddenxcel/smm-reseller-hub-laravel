<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\NumberRental;
use App\Services\Numbers\RentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RentNumberController extends Controller
{
    public function __construct(private RentNumber $rentals) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'platform_number_id' => ['required', 'integer'],
            'bot_type' => ['required', Rule::in(['order', 'support'])],
        ]);

        try {
            $this->rentals->claim(
                $request->user(),
                (int) $validated['platform_number_id'],
                $validated['bot_type'],
            );
        } catch (RuntimeException $e) {
            // Losing a race for a number is an ordinary outcome, not a fault
            // — it belongs on the form, not on an error page.
            throw ValidationException::withMessages([
                'platform_number_id' => $e->getMessage(),
            ]);
        }

        return $this->afterSave($request)
            ->with('status', 'Number rented — your bot is ready to use it.');
    }

    public function destroy(Request $request, NumberRental $rental): RedirectResponse
    {
        // The model is tenant-scoped, but a rental is a paid asset, so the
        // ownership check is spelled out where it can be read.
        if ($rental->tenant_id !== $request->user()->id) {
            abort(404);
        }

        $this->rentals->release($request->user(), $rental);

        return back(fallback: route('onboarding.step', 'whatsapp'))
            ->with('status', 'Number released.');
    }

    /**
     * Where to go after a setup action succeeds.
     *
     * The same forms serve two screens with opposite needs: the wizard must
     * advance to the next step, while Settings must stay on the tab the
     * reseller is working in. Submitting from Settings is the special case,
     * so that is what gets detected; everything else advances, which keeps
     * the wizard's behaviour identical to before Settings existed.
     */
    private function afterSave(Request $request): RedirectResponse
    {
        if (str_contains((string) $request->headers->get('referer'), '/settings')) {
            return back(fallback: route('settings'));
        }

        return redirect()->route('onboarding');
    }
}
