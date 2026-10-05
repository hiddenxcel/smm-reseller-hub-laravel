<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DeepSeek's chat completions API, spoken with a reseller's own key.
 *
 * Never throws. A customer waiting on WhatsApp must get an answer or a clear
 * "ask a human" — not a stack trace and not silence — so every failure comes
 * back as null and the caller decides what to say.
 *
 * The key belongs to the reseller and DeepSeek bills them for every call, so
 * the limits here are deliberately tight: one attempt, a short timeout, and a
 * cap on the reply length. A retry loop would double a bill they never agreed
 * to, and a customer who has already waited fifteen seconds has gone.
 */
class DeepSeekClient
{
    private const URL = 'https://api.deepseek.com/chat/completions';

    private const MODEL = 'deepseek-chat';

    /** A customer on WhatsApp will not wait longer than this for a reply. */
    private const TIMEOUT_SECONDS = 15;

    /**
     * Long enough for a real answer about a service and its price, short
     * enough that a runaway reply cannot run up the reseller's bill.
     */
    private const MAX_TOKENS = 400;

    /** Low, not zero: answers should be steady rather than inventive. */
    private const TEMPERATURE = 0.3;

    public function __construct(private string $apiKey) {}

    /**
     * Ask, and return the reply text.
     *
     * @param  string  $system  who the assistant is and what it may say
     * @param  array<int, array{role: string, content: string}>  $history
     *                                                                     earlier turns, oldest first, for follow-up questions
     * @return string|null null on any failure — unreachable, refused, or empty
     */
    public function ask(string $system, string $question, array $history = [], ?int $maxTokens = null): ?string
    {
        if ($this->apiKey === '') {
            return null;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::URL, [
                    'model' => self::MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ...$history,
                        ['role' => 'user', 'content' => $question],
                    ],
                    'max_tokens' => $maxTokens ?? self::MAX_TOKENS,
                    'temperature' => self::TEMPERATURE,
                    'stream' => false,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('DeepSeek unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            // The key itself is never logged, but which reseller and why is
            // worth knowing — an expired key looks identical to a broken bot
            // from the outside.
            Log::warning('DeepSeek refused a request', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return null;
        }

        $answer = $response->json('choices.0.message.content');

        if (! is_string($answer) || trim($answer) === '') {
            return null;
        }

        return trim($answer);
    }
}
