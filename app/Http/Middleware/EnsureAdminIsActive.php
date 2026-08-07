<?php

namespace App\Http\Middleware;

use App\Services\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the console itself, after `auth:superadmin` has established who is
 * asking.
 *
 * Two things are checked here that a session alone cannot answer:
 *
 * An admin disabled mid-session must stop working immediately, not at their
 * next login — the row is how someone is taken out of service, and a live
 * session would outlast it by hours.
 *
 * An admin inside a reseller's account must not also be operating the console.
 * The tenant session is live during impersonation, so admin screens would read
 * that reseller's data through a global scope that is no longer a no-op, and
 * show one account's numbers under another's name. Ending the visit is the way
 * back in.
 */
class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('superadmin')->user();

        if ($admin === null || ! $admin->isActive()) {
            Auth::guard('superadmin')->logout();

            return redirect()->route('admin.login')
                ->with('status', 'This admin account is no longer active.');
        }

        if (Impersonation::isActive($request)) {
            return redirect()->route('dashboard')
                ->with('error', 'Stop impersonating before returning to the admin console.');
        }

        return $next($request);
    }
}
