<?php

namespace App\Http\Middleware;

use App\Models\TeamMember;
use App\Services\Team\TeamAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows a team member's session to their role.
 *
 * A member signs in and is then, to the rest of the app, the reseller's own
 * account — so every controller already scopes to the right tenant, and this is
 * the only thing standing between a member and the owner's money, keys and
 * team. That is why it is on the whole web group rather than on routes: a route
 * added next month is closed to members by default, not open until someone
 * remembers to lock it. Same pattern as LockDemoAccount.
 *
 * The member is looked up on every request. Removing someone therefore takes
 * effect on their very next click, rather than whenever their session happens
 * to expire.
 */
class RestrictTeamMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $memberId = $request->session()->get('team_member_id');

        if ($memberId === null) {
            return $next($request);
        }

        $tenant = $request->user();

        // A leftover marker with nobody signed in is harmless, but is cleared so
        // it cannot attach itself to a later, unrelated sign-in.
        if ($tenant === null) {
            $request->session()->forget('team_member_id');

            return $next($request);
        }

        $member = TeamMember::where('id', $memberId)
            ->where('tenant_id', $tenant->id)
            ->first();

        // Removed, never activated, or the session belongs to another account:
        // end it. Sending the person to the login page says plainly that the
        // access is gone, instead of leaving them on a page of errors.
        if ($member === null || ! $member->isActive()) {
            Auth::guard('tenant')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Your access to that account has ended.');
        }

        $request->attributes->set('team_member', $member);

        $allowed = TeamAccess::allows(
            $member->role,
            $request->route()?->getName(),
            $request->method(),
            is_string($request->input('action')) ? $request->input('action') : null,
        );

        if ($allowed) {
            return $next($request);
        }

        return $this->refuse($request, $member);
    }

    /**
     * The three ways a request can ask, answered in kind: a fetch for JSON gets
     * a 403, an unsafe request goes back where it came from, and a page the role
     * cannot open lands on the dashboard — which every role can.
     */
    private function refuse(Request $request, TeamMember $member): Response
    {
        $message = 'Your '.strtolower(TeamAccess::LABELS[$member->role] ?? 'team').' access does not include that.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            return redirect()->route('dashboard')->with('error', $message);
        }

        return redirect()->back(fallback: route('dashboard'))->with('error', $message);
    }
}
