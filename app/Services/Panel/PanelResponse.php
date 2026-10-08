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
        /** The HTTP status, when the failure came from one (403 means not permitted). */
        public ?int $code = null,
    ) {}

    public static function ok(array $data): self
    {
        return new self(failed: false, data: $data, message: null);
    }

    public static function error(string $message, ?int $code = null): self
    {
        return new self(failed: true, data: [], message: $message, code: $code);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
