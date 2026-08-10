<?php

namespace App\Http\Controllers\Admin;

use App\Models\Superadmin;
use App\Services\Admin\AdminUserActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people who can operate the console.
 *
 * Owner-only throughout: creating admins and changing roles is how someone
 * would grant themselves more than they have, so the grade that can do it is
 * the one that already has everything.
 */
class AdminUsersController extends AdminController
{
    private const ROLES = ['owner', 'admin', 'support'];

    public function index(): Response
    {
        $this->authoriseOwner('manage admin accounts');

        return Inertia::render('Admin/Users/Index', [
            'admins' => Superadmin::orderBy('username')
                ->get()
                ->map(fn (Superadmin $admin) => AdminUserActions::toRow($admin))
                ->all(),
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authoriseOwner('manage admin accounts');

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('superadmins', 'username')],
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('superadmins', 'email')],
            'role' => ['required', Rule::in(self::ROLES)],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $password = $validated['password'];
        unset($validated['password']);

        AdminUserActions::create($validated, $password);

        return back()->with(
            'success',
            "{$validated['username']} can now sign in. Send them the password over a channel they already use.",
        );
    }

    public function update(Request $request, Superadmin $admin): RedirectResponse
    {
        $this->authoriseOwner('manage admin accounts');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('superadmins', 'email')->ignore($admin->id)],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $actions = AdminUserActions::for($admin);

        // Demoting the last owner would leave a console nobody can administer,
        // recoverable only by editing the database by hand.
        if ($validated['role'] !== 'owner' && $actions->isLastOwner()) {
            return back()->with('error', 'This is the last active owner — promote someone else first.');
        }

        $actions->update($validated);

        return back()->with('success', 'Admin updated.');
    }

    public function act(Request $request, Superadmin $admin, string $action): RedirectResponse
    {
        $this->authoriseOwner('manage admin accounts');

        $actions = AdminUserActions::for($admin);

        return match ($action) {
            'disable' => $this->disable($actions, $admin),
            'enable' => $this->enable($actions, $admin),
            'password' => $this->password($request, $actions, $admin),
        };
    }

    private function disable(AdminUserActions $actions, Superadmin $admin): RedirectResponse
    {
        if ($actions->isLastOwner()) {
            return back()->with('error', 'This is the last active owner — nobody could administer the console.');
        }

        // Disabling yourself is the same mistake with a shorter fuse.
        if ($admin->id === $this->admin()->id) {
            return back()->with('error', 'You cannot disable your own account.');
        }

        $actions->disable();

        return back()->with('success', "{$admin->username} can no longer sign in.");
    }

    private function enable(AdminUserActions $actions, Superadmin $admin): RedirectResponse
    {
        $actions->enable();

        return back()->with('success', "{$admin->username} can sign in again.");
    }

    private function password(Request $request, AdminUserActions $actions, Superadmin $admin): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $actions->setPassword($validated['password']);

        return back()->with('success', "Password set for {$admin->username}.");
    }
}
