<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\ApiLog;
use Illuminate\Support\Facades\Log;

/**
 * Writes the api_logs row for a request.
 *
 * Logging must never be the reason a request fails: a customer's order should
 * not be refused because the log table is full or locked. A failure to record
 * goes to the application log and the request carries on.
 */
class ApiLogger
{
    public function record(
        ?ApiKey $key,
        ?string $action,
        ?string $ip,
        bool $ok,
        ?string $error = null,
        array $details = [],
        ?int $durationMs = null,
    ): void {
        try {
            ApiLog::create([
                // Not taken from the session: a refused request has no tenant
                // yet, and the key is the only thing that knows whose it is.
                'tenant_id' => $key?->tenant_id,
                'api_key_id' => $key?->getKey(),
                // Actions are a known set, but this records what was asked
                // for, including a nonsense value — truncated so a long one
                // cannot overflow the column.
                'action' => $action === null ? null : mb_substr($action, 0, 30),
                'ip' => $ip,
                'ok' => $ok,
                'error' => $error === null ? null : mb_substr($error, 0, 255),
                'details' => $details === [] ? null : $details,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not write API log', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
