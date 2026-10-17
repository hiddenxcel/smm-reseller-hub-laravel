<?php

namespace App\Http\Controllers;

use App\Models\TenantTelegramBot;
use App\Services\Bots\Telegram\TelegramConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Connect or remove the Telegram bot behind a reseller's Order or Support bot.
 * The token is typed here, never sent in chat.
 */
class TelegramBotController extends Controller
{
    public function store(Request $request, TelegramConnector $connector): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200', 'regex:/^\d{5,}:[\w-]{20,}$/'],
            'bot_type' => ['required', Rule::in(['order', 'support'])],
        ], ['token.regex' => 'That does not look like a BotFather token (digits, a colon, then letters).']);

        $bot = $connector->connect((int) $request->user()->id, $data['bot_type'], $data['token']);

        return back()->with('success', "Connected as @{$bot->bot_username}.");
    }

    public function destroy(Request $request, int $bot, TelegramConnector $connector): RedirectResponse
    {
        $row = TenantTelegramBot::withoutTenantScope()
            ->where('tenant_id', $request->user()->id)
            ->whereKey($bot)
            ->firstOrFail();

        $connector->disconnect($row);

        return back()->with('success', 'Telegram bot disconnected.');
    }
}
