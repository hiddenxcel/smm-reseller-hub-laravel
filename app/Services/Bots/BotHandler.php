<?php

namespace App\Services\Bots;

/**
 * What the router needs from a bot: given a sender and their message, take it
 * from here. State lives in bot_conversations, so handlers are stateless
 * between calls.
 */
interface BotHandler
{
    public function handle(string $from, string $text): void;
}
