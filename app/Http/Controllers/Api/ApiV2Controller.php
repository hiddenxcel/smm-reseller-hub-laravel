<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Api\Actions\AddAction;
use App\Services\Api\Actions\ApiAction;
use App\Services\Api\Actions\BalanceAction;
use App\Services\Api\Actions\OrdersAction;
use App\Services\Api\Actions\RefillAction;
use App\Services\Api\Actions\ServicesAction;
use App\Services\Api\Actions\StatusAction;
use App\Services\Api\ApiLogger;
use App\Services\Api\ApiResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The single endpoint of the SMM API v2.
 *
 * One URL, with `action` in the body deciding what happens, because that is
 * the convention every SMM panel implements and every client library expects.
 * It is not how this app would route anything else — see routes/api.php.
 *
 * Two conventions of that standard are load-bearing here:
 *   - Failures come back as {"error": "..."} with HTTP 200. Clients read the
 *     body and ignore the status, so a "correct" 4xx breaks them.
 *   - Everything is form-encoded POST, not JSON.
 */
class ApiV2Controller extends Controller
{
    /**
     * `status` maps to two different handlers: one order under `order`, many
     * under `orders`. The convention overloads the name, and resolve() picks
     * on which field was sent.
     */
    private const ACTIONS = [
        'services' => ServicesAction::class,
        'add' => AddAction::class,
        'status' => StatusAction::class,
        'refill' => RefillAction::class,
        'balance' => BalanceAction::class,
    ];

    public function __construct(private ApiLogger $logger) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var ApiKey $key The middleware has already resolved this. */
        $key = $request->attributes->get('api_key');

        $name = $request->input('action');
        $name = is_string($name) ? mb_strtolower(trim($name)) : '';

        $handler = $this->resolve($name, $request);

        if ($handler === null) {
            return $this->respond($request, $key, $name, ApiResult::error('Invalid action'), 0);
        }

        $startedAt = hrtime(true);

        try {
            $result = $handler->handle($request, $key);
        } catch (\Throwable $e) {
            // The stack trace is ours; the caller gets a flat message. An
            // exception here is a bug or an outage, and either way the
            // reseller's customer must not see the internals.
            Log::error('API action failed', [
                'action' => $name,
                'api_key_id' => $key->getKey(),
                'exception' => $e,
            ]);

            $result = ApiResult::error('Something went wrong, please try again');
        }

        return $this->respond(
            $request,
            $key,
            $name,
            $result,
            (int) ((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    private function resolve(string $name, Request $request): ?ApiAction
    {
        if ($name === 'status' && $request->has('orders')) {
            return app(OrdersAction::class);
        }

        $class = self::ACTIONS[$name] ?? null;

        return $class === null ? null : app($class);
    }

    private function respond(
        Request $request,
        ?ApiKey $key,
        string $action,
        ApiResult $result,
        int $durationMs,
    ): JsonResponse {
        $this->logger->record(
            key: $key,
            action: $action === '' ? null : $action,
            ip: $request->ip(),
            ok: $result->ok,
            error: $result->error,
            details: $result->details,
            durationMs: $durationMs,
        );

        return response()->json(
            $result->ok ? $result->data : ['error' => $result->error],
        );
    }
}
