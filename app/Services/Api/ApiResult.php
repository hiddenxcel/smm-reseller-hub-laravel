<?php

namespace App\Services\Api;

/**
 * What an action hands back: either a payload or a reason it failed.
 *
 * Actions return this rather than a response so they stay testable without a
 * request, and so one place — ApiV2Controller — decides how a result becomes
 * JSON. That matters because the SMM API's error shape is a body convention,
 * not an HTTP one, and it should be written once.
 */
final class ApiResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly array $data,
        public readonly ?string $error,
        /** Recorded in api_logs, never sent to the caller. */
        public readonly array $details,
    ) {}

    public static function ok(array $data, array $details = []): self
    {
        return new self(true, $data, null, $details);
    }

    public static function error(string $message, array $details = []): self
    {
        return new self(false, [], $message, $details);
    }
}
