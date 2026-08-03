<?php

namespace App\Services\Orders;

/** The outcome of one action on one order. */
final readonly class ActionResult
{
    private function __construct(
        public bool $failed,
        public string $message,
    ) {}

    public static function ok(string $message): self
    {
        return new self(failed: false, message: $message);
    }

    public static function failed(string $message): self
    {
        return new self(failed: true, message: $message);
    }
}
