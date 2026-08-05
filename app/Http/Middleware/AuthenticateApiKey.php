<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\BotCustomer;
use App\Services\Api\ApiLogger;
use App\Services\Api\ApiRateLimiter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the `key` field on an API request into an authenticated tenant.
 *
 * Every refusal here is logged, because a caller who cannot get in is exactly
 * the caller whose reseller will be asked what went wrong.
 *
 * The tenant guard is set for the duration of the request so TenantScope
 * applies as it does everywhere else — the API gets its isolation from the
 * same mechanism the screens do, not from remembering to filter.
 */
class AuthenticateApiKey
{
    public function __construct(
        private ApiRateLimiter $limiter,
        private ApiLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->input('key');
        $ip = $request->ip();
        $action = $request->input('action');

        if (! is_string($presented) || $presented === '') {
            return $this->refuse($request, 'Invalid API key', null);
        }

        // Unscoped by necessity: there is no session yet, and the key is what
        // establishes which tenant this is.
        $key = ApiKey::withoutTenantScope()
            ->where('key_hash', ApiKey::hash($presented))
            ->first();

        if ($key === null || ! $key->isActive()) {
            // Deliberately the same message for "no such key" and "revoked":
            // the difference is only useful to someone probing.
            return $this->refuse($request, 'Invalid API key', $key);
        }

        if (! $key->allowsIp($ip)) {
            return $this->refuse($request, 'This IP address is not allowed to use this key', $key);
        }

        if (! $this->limiter->attempt($key)) {
            return $this->refuse($request, 'Too many requests', $key);
        }

        $tenant = $key->tenant;

        // A suspended reseller's shop stops taking orders. Said plainly to the
        // caller — they are a customer of the reseller, and "the shop is
        // closed" is what they need to know.
        if ($tenant === null || $tenant->status !== 'active') {
            return $this->refuse($request, 'This shop is not accepting orders', $key);
        }

        // Read unscoped, and deliberately: the tenant guard is not set until
        // the line below, so TenantScope would either find no tenant to scope
        // to or — in a worker or a test running several keys in one process —
        // still be holding the *previous* request's tenant, and silently
        // resolve nothing. Establishing who the caller is cannot depend on
        // already knowing.
        $customer = BotCustomer::withoutTenantScope()
            ->whereKey($key->customer_id)
            ->first();

        // A blocked customer is blocked here too, exactly as in the bot.
        if ($customer === null || $customer->isBlocked()) {
            return $this->refuse($request, 'This account cannot place orders', $key);
        }

        Auth::guard('tenant')->setUser($tenant);

        // Now that the guard is set, hand the action a key whose `customer`
        // relation is already loaded — so nothing downstream re-queries it and
        // gets a different answer.
        $key->setRelation('customer', $customer);
        $request->attributes->set('api_key', $key);

        // A best-effort touch: two requests in the same second may write the
        // same value, which is fine — this is for "when did this key last
        // work", not an audit trail.
        $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();

        return $next($request);
    }

    private function refuse(Request $request, string $message, ?ApiKey $key): Response
    {
        $this->logger->record(
            key: $key,
            action: is_string($request->input('action')) ? $request->input('action') : null,
            ip: $request->ip(),
            ok: false,
            error: $message,
        );

        // 200, like the rest of this API: the SMM convention puts failures in
        // the body, and clients written against it read `error` and ignore the
        // status. A 401 here would break callers that work everywhere else.
        return response()->json(['error' => $message]);
    }
}
