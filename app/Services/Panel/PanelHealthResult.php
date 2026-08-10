<?php

namespace App\Services\Panel;

/**
 * What one health check found.
 *
 * `changed` is the field the scheduled command reports on: a run where forty
 * panels were checked and nothing changed is the normal case, and saying so in
 * one line is more useful than forty lines saying "still fine".
 */
final readonly class PanelHealthResult
{
    private function __construct(
        public bool $healthy,
        public ?float $balance,
        public ?string $message,
        public bool $changed,
    ) {}

    public static function up(?float $balance, bool $recovered): self
    {
        return new self(
            healthy: true,
            balance: $balance,
            message: null,
            changed: $recovered,
        );
    }

    public static function down(string $message, bool $justFailed): self
    {
        return new self(
            healthy: false,
            balance: null,
            message: $message,
            changed: $justFailed,
        );
    }
}
