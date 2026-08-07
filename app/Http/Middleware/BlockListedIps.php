<?php

namespace App\Http\Middleware;

use App\Models\BlockedIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses requests from addresses an admin has blocked.
 *
 * Applied to the web group, not to webhooks: a gateway confirming a payment
 * arrives from the gateway's own address, and a block placed for an unrelated
 * reason must never stop money being credited to a reseller.
 *
 * The lookup is cached for a minute (see BlockedIp::blocks), so this costs
 * nothing on the vast majority of requests.
 */
class BlockListedIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if ($ip !== null && BlockedIp::blocks($ip)) {
            // 403 with nothing else: a blocked address should learn as little
            // as possible about what it was refused from.
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
