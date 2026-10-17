<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\TenantTelegramBot;
use App\Services\Bots\BotRouter;
use App\Services\Bots\InboundMessage;
use App\Services\Bots\Telegram\TelegramApi;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Where Telegram delivers a reseller's bot messages. The path names the bot
 * (an unguessable id); the secret header proves the call came from Telegram.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(private BotRouter $router) {}

    public function handle(Request $request, string $publicId): Response
    {
        $bot = TenantTelegramBot::findByPublicId($publicId);

        // The same answer for a wrong path and a wrong secret: nothing to learn.
        if ($bot === null || ! hash_equals($bot->webhook_secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            return response('forbidden', 403);
        }

        if ($bot->status !== 'active') {
            return $this->ack('inactive');
        }

        $update = $request->json()->all();

        // Telegram redelivers an update it did not get a 200 for; one that was
        // answered must not be answered twice.
        $updateId = $update['update_id'] ?? null;

        if ($updateId !== null && ! Cache::add("tg-update:{$bot->id}:{$updateId}", 1, now()->addDay())) {
            return $this->ack('duplicate');
        }

        $message = InboundMessage::fromTelegramUpdate($update, $bot->public_id);

        // A button tap shows a spinner until it is answered, so do that first,
        // whatever happens to the message afterwards.
        if (isset($update['callback_query']['id'])) {
            (new TelegramApi((string) $bot->token_enc))
                ->call('answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id']]);
        }

        if ($message === null) {
            return $this->ack('no_message');
        }

        try {
            $route = $this->router->routeTelegram($bot, $message)->value;
        } catch (\Throwable $e) {
            // Telegram retries anything that is not a 200; a retry would replay
            // the whole conversation step.
            Log::error('Telegram webhook failed', ['error' => $e->getMessage(), 'exception' => $e]);

            $route = 'error';
        }

        return $this->ack($route);
    }

    private function ack(string $status): Response
    {
        return response($status)->header('Content-Type', 'text/plain');
    }
}
