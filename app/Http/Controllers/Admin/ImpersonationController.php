<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Admin\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ImpersonationController extends Controller
{
    /**
     * Enter a reseller's account, read-only.
     *
     * The tenant is resolved with withoutTenantScope() deliberately: this runs
     * inside an admin session with no tenant of its own, and being explicit here
     * means the lookup keeps working if that ever changes.
     */
    public function store(Request $request, int $tenant): RedirectResponse
    {
        $admin = Auth::guard('superadmin')->user();

        if (! $admin->can('tenants.impersonate')) {
            throw new AccessDeniedHttpException('Your role cannot impersonate resellers.');
        }

        $validated = $request->validate([
            // Why an admin entered someone's account is the first thing asked
            // when a reseller queries it, so it is recorded at the door.
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = Tenant::findOrFail($tenant);

        Impersonation::start($admin, $target, $request, $validated['reason'] ?? null);

        return redirect()->route('dashboard')
            ->with('success', "Viewing {$target->business_name} as an admin. This session is read-only.");
    }

    /** Leave the account and go back to where the admin came from. */
    public function destroy(Request $request): RedirectResponse
    {
        $record = Impersonation::current($request);

        Impersonation::stop($request);

        if ($record === null) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('admin.tenants.show', $record->tenant_id)
            ->with('success', 'Impersonation ended.');
    }
}
