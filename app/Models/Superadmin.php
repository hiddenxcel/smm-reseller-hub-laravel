<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The platform owner (HiddenXcel), behind /hx-control. Deliberately
 * separate from Tenant — a distinct guard so a tenant session can never
 * be mistaken for a super-admin session.
 */
class Superadmin extends Authenticatable implements AuthenticatableContract
{
    use HasFactory;

    public $timestamps = false;

    /**
     * What each grade may do. Roles are cumulative in practice but listed in
     * full per role rather than inherited, so reading one line answers the
     * question without tracing a chain.
     *
     * `support` deliberately cannot touch money or other admins: the grade
     * exists so someone can answer tickets and look at accounts without being
     * able to refund a payment or grant themselves the owner role.
     */
    private const PERMISSIONS = [
        'owner' => ['*'],
        'admin' => [
            'tenants.view', 'tenants.edit', 'tenants.suspend', 'tenants.impersonate',
            'billing.view', 'billing.manage',
            'tickets.view', 'tickets.manage',
            'audit.view',
        ],
        'support' => [
            'tenants.view', 'tenants.impersonate',
            'billing.view',
            'tickets.view', 'tickets.manage',
        ],
    ];

    protected $fillable = [
        'username',
        'name',
        'email',
        'password_hash',
        'role',
        'status',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function impersonations(): HasMany
    {
        return $this->hasMany(AdminImpersonation::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The owner grade is unconditional; every other grade is a fixed list. */
    public function can($ability, $arguments = []): bool
    {
        $granted = self::PERMISSIONS[$this->role] ?? [];

        return in_array('*', $granted, true)
            || in_array($ability, $granted, true);
    }

    /** Display name, falling back to the username when none was set. */
    public function displayName(): string
    {
        return $this->name ?: $this->username;
    }
}
