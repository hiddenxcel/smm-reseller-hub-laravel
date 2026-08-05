<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The /hx-control front door.
 *
 * Separate from the tenant's AuthenticatedSessionController on purpose: a
 * single controller serving both guards is one wrong argument away from
 * logging a reseller into the admin console.
 */
class AdminSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Admin/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(AdminLoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $admin = Auth::guard('superadmin')->user();

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        AdminAudit::record('admin.login');

        return redirect()->intended(route('admin.dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Logging out while inside a reseller's account would leave that tenant
        // session live with nobody accountable for it, and an impersonation row
        // that never closes. Ending the visit first is what stops both.
        if (Impersonation::isActive($request)) {
            Impersonation::stop($request);
        }

        AdminAudit::record('admin.logout');

        Auth::guard('superadmin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
