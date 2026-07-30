<?php

namespace Tests\Support;

use App\Models\Tenant;
use App\Services\Bots\BotHandler;
use App\Services\Bots\BotMessenger;

/**
 * Records that the router dispatched to it, and with what — the router's job
 * ends at choosing a handler, so its tests should not depend on real bot
 * behaviour. Subclassed per bot so tests can tell them apart.
 */
abstract class FakeBotHandler implements BotHandler
{
    /** @var array<int, array{bot: string, tenantId: int, from: string, text: string}> */
    public static array $handled = [];

    public function __construct(
        private Tenant $tenant,
        private BotMessenger $messenger,
    ) {}

    abstract public function bot(): string;

    public static function reset(): void
    {
        self::$handled = [];
    }

    /** Which bot ran, or null if the router dispatched nowhere. */
    public static function dispatchedTo(): ?string
    {
        return self::$handled === [] ? null : self::$handled[0]['bot'];
    }

    public function handle(string $from, string $text): void
    {
        self::$handled[] = [
            'bot' => $this->bot(),
            'tenantId' => (int) $this->tenant->id,
            'from' => $from,
            'text' => $text,
        ];
    }
}
