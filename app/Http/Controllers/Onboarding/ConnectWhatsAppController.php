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

        return back(fallback: route('onboarding'))
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
}
