<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Services\Numbers\RentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RentNumberController extends Controller
{
    public function __construct(private RentNumber $rentals) {}

    /**
     * Choose a number. This does NOT rent it.
     *
     * A number is rented when it is paid for — ActivatePurchase claims it when
     * the gateway confirms the invoice. Choosing only carries the pick over to
     * the payment screen, so a number nobody has paid for is never shown as
     * rented, never leaves the pool, and never reaches the reseller's bot.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'platform_number_id' => ['required', 'integer'],
            'bot_type' => ['sometimes', Rule::in(['order', 'support'])],
        ]);

        $botType = $validated['bot_type'] ?? 'order';

        try {
            $this->rentals->assertBotTypeIsFree($request->user(), $botType);

            $number = PlatformNumber::where('status', 'available')->find((int) $validated['platform_number_id']);

            if ($number === null) {
                throw new RuntimeException('That number has just been taken. Please pick another.');
            }
        } catch (RuntimeException $e) {
            // Losing a race for a number is an ordinary outcome, not a fault
            // — it belongs on the form, not on an error page.
            throw ValidationException::withMessages([
                'platform_number_id' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('billing', ['number' => $number->id, 'bot' => $botType])
            ->with('status', 'Number chosen. It becomes yours once the payment clears — pay below to activate it.');
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
}
