<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantKey;
use App\Services\Simulator\BotSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The practice chat for someone with no account.
 *
 * The same screen and the same engine a signed-up reseller gets in the wizard,
 * minus the reseller: the shop is made up for each message and thrown away with
 * the rest of the transaction, so a stranger can reach this without being able
 * to leave anything behind. What they carry from one message to the next lives
 * in their own session.
 */
class TryController extends Controller
{
    private const SESSION_KEY = 'simulator.try';

    private const DEFAULT_SHOP = 'Your Shop';

    public function show(): Response
    {
        return Inertia::render('Public/Try', [
            'simulator' => [
                'endpoint' => route('try.send'),
                'business' => self::DEFAULT_SHOP,
                'bots' => BotSimulator::BOTS,
                'startingBalance' => BotSimulator::STARTING_BALANCE,
                'sendBusiness' => true,
            ],
            'demoNumber' => config('services.demo_whatsapp_number'),
            'assistantEnabled' => AssistantKey::isReady(),
        ]);
    }

    public function send(Request $request, BotSimulator $simulator): JsonResponse
    {
        $data = $request->validate([
            'bot' => ['required', 'in:'.implode(',', BotSimulator::BOTS)],
            'text' => ['nullable', 'string', 'max:500'],
            'reset' => ['sometimes', 'boolean'],
            'opening' => ['sometimes', 'boolean'],
            'business' => ['nullable', 'string', 'max:40'],
        ]);

        if ($request->boolean('reset')) {
            $request->session()->forget(self::SESSION_KEY);
        }

        $result = $simulator->send(
            null,
            $data['bot'],
            trim((string) ($data['text'] ?? '')) ?: 'hi',
            (array) $request->session()->get(self::SESSION_KEY, []),
            $this->shopName($data['business'] ?? null),
        );

        $request->session()->put(self::SESSION_KEY, $result['state']);

        return response()->json([
            'events' => $result['events'],
            'sample' => true,
            'balance' => $result['state']['balance'],
        ]);
    }

    /**
     * What the visitor calls their shop. It is drawn back into the bot's
     * greeting, so it is cut down to letters, digits and ordinary punctuation:
     * whatever else was typed has no business in a WhatsApp message.
     */
    private function shopName(?string $typed): string
    {
        $clean = trim(preg_replace('/[^\p{L}\p{N} .&\'-]+/u', '', (string) $typed) ?? '');

        return $clean === '' ? self::DEFAULT_SHOP : mb_substr($clean, 0, 40);
    }
}
