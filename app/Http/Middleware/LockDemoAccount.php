<?php

namespace App\Http\Middleware;

use App\Services\Demo\DemoAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The demo account is read-only, and this is what makes that true.
 *
 * Its credentials are published, so the person signed in is not the person who
 * owns the data — and there may be several of them at once. Hiding buttons is
 * not enforcement: anything the session could normally post would still go
 * through from a stale form, a bookmarked URL, or curl. So the check is on the
 * HTTP method, the same way BlockDuringImpersonation does it.
 *
 * The scheduled reset is a second line, not this one. Ten minutes is long
 * enough to change the password and lock everyone else out, point a panel at
 * an attacker's URL, or spend the reseller's AI credits — none of which a
 * later rebuild undoes for the people affected in the meantime.
 */
class LockDemoAccount
{
    /** Read-only methods, which are allowed through regardless. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Signing out has to keep working.
     *
     * It is a POST — correctly, since it changes state — but refusing it would
     * trap a visitor in an account they cannot leave, on a shared login where
     * the next person then inherits their session.
     */
    private const ALWAYS_ALLOWED = ['logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoAccount::is($request->user())) {
            return $next($request);
        }

        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if ($request->routeIs(self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        // Inertia renders a 403 as a hard error page, which would strand the
        // visitor with no way back and make a working demo look broken.
        // Bouncing them to where they were keeps the account browsable, which
        // is the entire point of it existing.
        return back()->with('error', config('demo.message'));
    }
}
