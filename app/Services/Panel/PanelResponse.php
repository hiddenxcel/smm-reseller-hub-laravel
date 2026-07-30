<?php

namespace App\Services\Panel;

/**
 * A reply from a reseller's SMM panel. The API signals failure in the body
 * rather than the HTTP status, so callers must check `failed` — a 200 alone
 * means nothing.
 */
final readonly class PanelResponse
{
    private function __construct(
        public bool $failed,
        public array $data,
        public ?string $message,
    ) {}

    public static function ok(array $data): self
    {
        return new self(failed: false, data: $data, message: null);
    }

    public static function error(string $message): self
    {
        return new self(failed: true, data: [], message: $message);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
