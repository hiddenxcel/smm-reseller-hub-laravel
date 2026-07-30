<?php

namespace App\Services\Bots;

use App\Models\Tenant;

/**
 * Builds the handler for a given bot, bound to the tenant and the channel it
 * should reply on.
 *
 * The order and support handlers are ported in the next step; until then this
 * resolves them through the container so the router can be tested against
 * fakes.
 */
class BotHandlerFactory
{
    /** @var array<string, class-string<BotHandler>> */
    private array $handlers = [];

    /** @param class-string<BotHandler> $handler */
    public function register(string $bot, string $handler): void
    {
        $this->handlers[$bot] = $handler;
    }

    public function for(string $bot, Tenant $tenant, BotMessenger $messenger): BotHandler
    {
        $handler = $this->handlers[$bot] ?? null;

        if ($handler === null) {
            throw new \InvalidArgumentException("No handler registered for bot '{$bot}'");
        }

        return new $handler($tenant, $messenger);
    }
}
