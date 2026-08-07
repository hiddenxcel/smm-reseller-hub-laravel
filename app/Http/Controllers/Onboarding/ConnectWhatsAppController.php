<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\TenantWhatsApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConnectWhatsAppController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone_number_id' => ['required', 'string', 'max:50'],
            'token' => ['required', 'string', 'max:500'],
            'waba_id' => ['nullable', 'string', 'max:50'],
            'display_number' => ['nullable', 'string', 'max:30'],
            'bot_type' => ['required', Rule::in(['order', 'support'])],
        ]);

        $tenant = $request->user();

        $this->assertNumberIsFree($validated['phone_number_id'], $tenant->id);
        $this->assertBotIsUnclaimed($validated['bot_type'], $validated['phone_number_id'], $tenant->id);

        // Keyed on phone_number_id so a reseller can attach a second number —
        // one for the order bot, another for support.
        TenantWhatsApp::updateOrCreate(
            ['phone_number_id' => $validated['phone_number_id']],
            [
                'tenant_id' => $tenant->id,
                'source' => 'own',
                'cloud_api_token_enc' => $validated['token'],
                'waba_id' => $validated['waba_id'] ?? null,
                'display_number' => $validated['display_number'] ?? null,
                'bot_type' => $validated['bot_type'],
                'status' => 'active',
            ],
        );

        return $this->afterSave($request)
            ->with('status', 'WhatsApp number connected.');
    }

    /**
     * phone_number_id is how an inbound webhook finds its tenant, so two
     * resellers claiming the same one would misroute real conversations.
     */
    private function assertNumberIsFree(string $phoneNumberId, int $tenantId): void
    {
        $takenByAnother = TenantWhatsApp::withoutTenantScope()
            ->where('phone_number_id', $phoneNumberId)
            ->where('tenant_id', '!=', $tenantId)
            ->exists();

        if ($takenByAnother) {
            throw ValidationException::withMessages([
                'phone_number_id' => 'That number is already connected to another account.',
            ]);
        }
    }

    /**
     * One bot per number, and one number per bot: if another of this
     * reseller's numbers already runs the order bot, a second cannot also
     * claim it — an inbound message would have no single answer to "whose bot
     * is this?".
     */
    private function assertBotIsUnclaimed(string $botType, string $phoneNumberId, int $tenantId): void
    {
        $conflicting = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('phone_number_id', '!=', $phoneNumberId)
            ->where('bot_type', $botType)
            ->exists();

        if ($conflicting) {
            throw ValidationException::withMessages([
                'bot_type' => 'Another of your numbers already runs that bot.',
            ]);
        }
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
