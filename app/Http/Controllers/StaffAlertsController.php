<?php

namespace App\Http\Controllers;

use App\Models\TenantWhatsApp;
use App\Services\Bots\BotMessengerFactory;
use App\Services\Bots\BotSettings;
use App\Services\Bots\StaffAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Checking that the team really gets told, and choosing how.
 *
 * WhatsApp can only reach someone who has written to the bot in the last 24
 * hours, so a number on the team list may be unreachable without anyone
 * knowing. A test alert answers it for one number in a click, and the email
 * setting says whether to be told by email as well, or only when WhatsApp
 * could not reach everyone.
 */
class StaffAlertsController extends Controller
{
    public function test(Request $request, string $bot, StaffAlerts $alerts, BotMessengerFactory $messengers): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $tenant = $request->user();
        $phone = preg_replace('/\D/', '', $data['phone']) ?? '';

        // Only a number on this bot's own team list: this must not become a
        // way to send a message from the reseller's number to anybody.
        if (! in_array($phone, StaffAlerts::numbers((int) $tenant->id, $bot), true)) {
            return back()->with('error', 'That number is not on your team list.');
        }

        $whatsapp = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('bot_type', $bot)
            ->where('status', 'active')
            ->first();

        if ($whatsapp === null) {
            return back()->with('error', 'This bot has no WhatsApp number connected to send from.');
        }

        $result = $alerts->test($tenant, $bot, $messengers->forWhatsApp($whatsapp, $tenant), $phone);

        return $result['status'] === 'sent'
            ? back()->with('success', "Test alert sent to {$phone}.")
            : back()->with('error', "Test alert was not delivered to {$phone}: ".StaffAlerts::reasonText($result['reason']).'.');
    }

    public function email(Request $request, string $bot): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(StaffAlerts::EMAIL_MODES)]]);

        $tenantId = (int) $request->user()->id;
        $settings = BotSettings::for($tenantId, $bot);
        $settings['staff']['email'] = $data['mode'];

        BotSettings::save($tenantId, $bot, $settings);

        return back()->with('success', 'Email alerts saved.');
    }
}
