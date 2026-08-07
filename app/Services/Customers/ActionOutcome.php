<?php

namespace App\Services\Customers;

/** The result of one action on one customer. */
final readonly class ActionOutcome
{
    private function __construct(
        public bool $failed,
        public string $message,
        public array $data = [],
    ) {}

    public static function ok(string $message, array $data = []): self
    {
        return new self(failed: false, message: $message, data: $data);
    }

    public static function failed(string $message): self
    {
        return new self(failed: true, message: $message);
    }
}
