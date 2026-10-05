<?php

namespace App\Http\Controllers;

use App\Services\Bots\BotSettings;
use App\Services\Simulator\BotSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The endpoint behind the WhatsApp screen in the browser.
 *
 * JSON rather than Inertia: the screen is a chat, and a reply must appear in it
 * without the page around it reloading. What the page needs to start is passed
 * in by the screen that hosts it — see OnboardingController.
 */
class SimulatorController extends Controller
{
    public function send(Request $request, BotSimulator $simulator): JsonResponse
    {
        $data = $request->validate([
            'bot' => ['required', 'in:'.implode(',', BotSimulator::BOTS)],
            // Empty is allowed so that opening the chat can be a "reset".
            'text' => ['nullable', 'string', 'max:1000'],
            'reset' => ['sometimes', 'boolean'],
            // Opening the chat sends a greeting that is not the visitor
            // typing anything, and must not count as having tried the bot.
            'opening' => ['sometimes', 'boolean'],
        ]);

        $tenant = $request->user();
        $key = 'simulator.'.$tenant->id;

        if ($request->boolean('reset')) {
            $request->session()->forget($key);
        }

        $result = $simulator->send(
            $tenant,
            $data['bot'],
            trim((string) ($data['text'] ?? '')) ?: 'hi',
            (array) $request->session()->get($key, []),
        );

        $request->session()->put($key, $result['state']);

        if (! $request->boolean('opening')) {
            $this->recordUse($tenant->id, $result['sample']);
        }

        return response()->json([
            'events' => $result['events'],
            'sample' => $result['sample'],
            'balance' => $result['state']['balance'],
        ]);
    }

    /**
     * Remember that the bot has been tried, and whether it was tried on the
     * reseller's own services — that is what lets them go live without a real
     * WhatsApp message. Written only when it changes: this runs on every
     * message.
     */
    private function recordUse(int $tenantId, bool $sample): void
    {
        $settings = BotSettings::for($tenantId, 'order');
        $changed = false;

        if (! Arr::get($settings, 'shop.bot_tried', false)) {
            Arr::set($settings, 'shop.bot_tried', true);
            $changed = true;
        }

        if (! $sample && ! Arr::get($settings, 'shop.sim_tested', false)) {
            Arr::set($settings, 'shop.sim_tested', true);
            $changed = true;
        }

        if ($changed) {
            BotSettings::save($tenantId, 'order', $settings);
        }
    }
}
