<?php

use App\Models\Superadmin;
use App\Models\Tenant;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | Two separate guards, matching the old platform's TenantAuth /
    | SuperadminAuth split: a tenant (reseller) login can never be mistaken
    | for a super-admin (/hx-control) login. There is no generic "users"
    | guard/table.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'tenant'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'tenants'),
    ],

    'guards' => [
        'tenant' => [
            'driver' => 'session',
            'provider' => 'tenants',
        ],

        'superadmin' => [
            'driver' => 'session',
            'provider' => 'superadmins',
        ],
    ],

    'providers' => [
        'tenants' => [
            'driver' => 'eloquent',
            'model' => Tenant::class,
        ],

        'superadmins' => [
            'driver' => 'eloquent',
            'model' => Superadmin::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | Only tenants self-serve a password reset; the super-admin password is
    | changed manually via /hx-control/settings.php (ported later), matching
    | the old platform's behaviour.
    |
    */

    'passwords' => [
        'tenants' => [
            'provider' => 'tenants',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
