<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\TenantPanel;
use App\Services\Panel\PanelDetector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ConnectPanelController extends Controller
{
    public function __construct(private PanelDetector $detector) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'api_url' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'max:255'],
        ]);

        $detection = $this->detector->detect($validated['api_url'], $validated['api_key']);

        if (! $detection->connected) {
            // Reported against api_url so the message lands next to the field
            // the reseller most likely got wrong.
            throw ValidationException::withMessages([
                'api_url' => $detection->message,
            ]);
        }

        $tenant = $request->user();

        $panel = TenantPanel::firstOrNew([
            'tenant_id' => $tenant->id,
            'api_url' => $detection->apiUrl,
        ]);

        // Named from the address rather than asked for. Only on the way in:
        // reconnecting an existing panel to rotate its key must not overwrite
        // a name the reseller has since chosen in Settings.
        if (! $panel->exists) {
            $panel->name = PanelDetector::nameFromUrl($validated['api_url']);
        }

        $panel->fill([
            'panel_type' => $detection->panelType(),
            'api_key_enc' => $validated['api_key'],
            'api_version' => 'v2',
            'auth_method' => $detection->authMethod,
            'last_checked_at' => now(),
            'last_balance' => $detection->balance,
            'balance_currency' => $detection->currency,
            'services_count' => $detection->servicesCount,
            'status' => 'active',
        ])->save();

        return $this->afterSave($request)
            ->with('status', 'Panel connected.');
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
