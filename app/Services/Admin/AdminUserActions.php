<?php

namespace App\Services\Admin;

use App\Models\Superadmin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Managing the people who can operate the console.
 *
 * Two rules protect the platform from its own admin screen.
 *
 * **Nobody can lock out the last owner.** An owner is the only grade that can
 * create admins or change roles, so disabling or demoting the last one would
 * leave a console nobody can administer — recoverable only by editing the
 * database by hand. Both paths check.
 *
 * **Disabling replaces deleting.** Every audit row points at an admin id; a
 * deleted row would orphan the trail that explains why a reseller's account
 * changed, which is the trail's entire purpose.
 */
class AdminUserActions
{
    public function __construct(private Superadmin $admin) {}

    public static function for(Superadmin $admin): self
    {
        return new self($admin);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes, string $password): Superadmin
    {
        $admin = Superadmin::create([
            ...$attributes,
            'password_hash' => Hash::make($password),
            'status' => 'active',
        ]);

        // The password itself is never recorded — an audit trail carrying
        // credentials is a breach waiting for someone to read it.
        AdminAudit::record('admins.create', [
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'role' => $admin->role,
        ]);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): void
    {
        $before = $this->admin->only(array_keys($attributes));

        $this->admin->update($attributes);

        AdminAudit::record('admins.update', [
            'admin_id' => $this->admin->id,
            'username' => $this->admin->username,
            'before' => $before,
            'after' => $attributes,
        ]);
    }

    public function setPassword(string $password): void
    {
        $this->admin->update(['password_hash' => Hash::make($password)]);

        AdminAudit::record('admins.password', [
            'admin_id' => $this->admin->id,
            'username' => $this->admin->username,
            // Worth distinguishing: an admin resetting their own password is
            // routine, someone resetting another's is not.
            'self' => $this->admin->id === Auth::guard('superadmin')->id(),
        ]);
    }

    public function disable(): void
    {
        $this->admin->update(['status' => 'disabled']);

        // Any open impersonation belongs to a session that is about to stop
        // working; leaving it open would read as somebody still inside an
        // account.
        Impersonation::closeOpenFor($this->admin->id);

        AdminAudit::record('admins.disable', [
            'admin_id' => $this->admin->id,
            'username' => $this->admin->username,
        ]);
    }

    public function enable(): void
    {
        $this->admin->update(['status' => 'active']);

        AdminAudit::record('admins.enable', [
            'admin_id' => $this->admin->id,
            'username' => $this->admin->username,
        ]);
    }

    /**
     * Would this change leave the console without an owner?
     *
     * Asked before disabling and before demoting. Counts active owners other
     * than this one — if there are none, this admin is the last way in.
     */
    public function isLastOwner(): bool
    {
        if ($this->admin->role !== 'owner' || ! $this->admin->isActive()) {
            return false;
        }

        return Superadmin::where('role', 'owner')
            ->where('status', 'active')
            ->whereKeyNot($this->admin->getKey())
            ->doesntExist();
    }

    public static function toRow(Superadmin $admin): array
    {
        return [
            'id' => $admin->id,
            'username' => $admin->username,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'status' => $admin->status,
            'lastLoginAt' => $admin->last_login_at?->toIso8601String(),
            'lastLoginIp' => $admin->last_login_ip,
            'isSelf' => $admin->id === Auth::guard('superadmin')->id(),
        ];
    }
}
