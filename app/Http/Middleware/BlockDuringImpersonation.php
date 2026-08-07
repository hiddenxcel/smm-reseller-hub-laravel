<?php

namespace App\Http\Middleware;

use App\Services\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impersonation is read-only, and this is what makes that true.
 *
 * Hiding buttons is not enforcement: an admin viewing a reseller's account is
 * holding a real tenant session, so anything that session could normally post —
 * a stale form, a bookmarked URL, a request replayed from devtools — would go
 * through. So the check is on the HTTP method, not on the screen.
 *
 * Everything unsafe is refused except the routes that end the visit, because an
 * admin must always be able to get back out.
 */
class BlockDuringImpersonation
{
    /** Read-only methods, which are allowed to pass regardless. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * The ways out, which have to keep working. Stopping ends the visit and
     * returns to the console; logging out ends both. Blocking either would
     * trap an admin inside a reseller's account.
     */
    private const ALWAYS_ALLOWED = [
        'admin.impersonate.stop',
        'admin.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Impersonation::isActive($request)) {
            return $next($request);
        }

        // Both guards are authenticated during an impersonation, so an
        // unqualified $request->user() — which ~96 places in the reseller
        // controllers use — resolves to whichever guard the resolver reaches
        // first, and hands them a Superadmin where a Tenant is expected. Pinning
        // it here fixes every one of those call sites at once, and keeps the
        // fix in the middleware that created the situation.
        $request->setUserResolver(
            fn (?string $guard = null) => Auth::guard($guard ?? 'tenant')->user(),
        );

        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if ($request->routeIs(self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        // Inertia treats 403 as a hard error page, which would strand the admin
        // on a screen with no way back. Bouncing them to where they were with a
        // flash message keeps the account browsable, which is the point.
        return back()->with('error', 'Read-only: you are viewing this account as an admin. Stop impersonating to make changes.');
    }
}
