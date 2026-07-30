<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    protected $fillable = [
        'username',
        'password_hash',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }
}
