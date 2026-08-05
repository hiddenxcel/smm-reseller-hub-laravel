<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Superadmin;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Shared plumbing for the console's controllers.
 *
 * Only the permission check lives here, and deliberately as a method every
 * action calls rather than as middleware: the abilities differ per action
 * (viewing a reseller is not suspending one), and a route-level guard would
 * have to be coarse enough to allow the loosest of them.
 */
abstract class AdminController extends Controller
{
    protected function admin(): Superadmin
    {
        return Auth::guard('superadmin')->user();
    }

    /**
     * Refuse unless the signed-in admin's role grants this ability.
     *
     * Throws rather than redirecting: a role that cannot do something is a
     * programming or permissions error, not a workflow the UI should recover
     * from — the screens already hide what they cannot offer.
     */
    protected function authorise(string $ability): void
    {
        if (! $this->admin()->can($ability)) {
            throw new AccessDeniedHttpException("Your role cannot {$ability}.");
        }
    }

    protected function can(string $ability): bool
    {
        return $this->admin()->can($ability);
    }
}
