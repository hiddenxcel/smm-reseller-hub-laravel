<?php

namespace App\Services\Bots\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The Telegram Bot API over HTTP, for one bot token.
 */
class TelegramApi
{
    public function __construct(private string $token) {}

    /**
     * Call a method. Returns the decoded response, or null when the call could
     * not be made at all. A refusal comes back as ['ok' => false, ...] so the
     * caller can tell "blocked by the user" from "unreachable".
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function call(string $method, array $params = []): ?array
    {
        if ($this->token === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->post("https://api.telegram.org/bot{$this->token}/{$method}", $params);
        } catch (ConnectionException $e) {
            // The URL carries the token, so the message is kept out of the log.
            Log::warning('Telegram API unreachable', ['method' => $method]);

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : ['ok' => false, 'error_code' => $response->status()];
    }
}
