<?php

namespace App\Services\Bots\Telegram;

use App\Models\TenantTelegramBot;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connects and disconnects a reseller's @BotFather bot.
 *
 * Connecting proves the token (getMe), then points the bot's webhook at us
 * with a secret Telegram sends back on every call. The token is stored
 * encrypted and never shown again.
 */
class TelegramConnector
{
    public function connect(int $tenantId, string $botType, string $token): TenantTelegramBot
    {
        $token = trim($token);
        $api = new TelegramApi($token);

        $me = $api->call('getMe');

        if (($me['ok'] ?? false) !== true || ! isset($me['result']['id'], $me['result']['username'])) {
            throw ValidationException::withMessages([
                'token' => $me === null
                    ? 'Telegram could not be reached. Try again in a moment.'
                    : 'Telegram did not accept that token. Copy it again from @BotFather.',
            ]);
        }

        $telegramId = (int) $me['result']['id'];

        $takenByAnother = TenantTelegramBot::withoutTenantScope()
            ->where('telegram_bot_id', $telegramId)
            ->where(fn ($q) => $q->where('tenant_id', '!=', $tenantId)->orWhere('bot_type', '!=', $botType))
            ->exists();

        if ($takenByAnother) {
            throw ValidationException::withMessages([
                'token' => 'That Telegram bot is already connected to another bot or account.',
            ]);
        }

        $existing = TenantTelegramBot::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', $botType)
            ->first();

        $publicId = $existing?->public_id ?? Str::random(32);
        $secret = Str::random(48);

        $hook = $api->call('setWebhook', [
            'url' => route('webhooks.telegram', $publicId),
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);

        if (($hook['ok'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'token' => 'Telegram refused the webhook: '.($hook['description'] ?? 'no answer').'.',
            ]);
        }

        // A replaced bot leaves its old webhook behind; switch it off.
        if ($existing !== null && $existing->telegram_bot_id !== $telegramId) {
            (new TelegramApi((string) $existing->token_enc))->call('deleteWebhook');
        }

        return TenantTelegramBot::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenantId, 'bot_type' => $botType],
            [
                'token_enc' => $token,
                'telegram_bot_id' => $telegramId,
                'bot_username' => (string) $me['result']['username'],
                'public_id' => $publicId,
                'webhook_secret' => $secret,
                'status' => 'active',
            ],
        );
    }

    public function disconnect(TenantTelegramBot $bot): void
    {
        (new TelegramApi((string) $bot->token_enc))->call('deleteWebhook');

        $bot->delete();
    }
}
