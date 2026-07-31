<?php

namespace Tests\Feature;

use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Logging in, including the "remember me" path.
 *
 * That path had no coverage and was broken: both auth models extend
 * Authenticatable, which writes remember_token, but neither table had the
 * column. The credentials check passed and the request then died on the
 * write — so it read as a server error on a correct password.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_can_log_in(): void
    {
        $tenant = Tenant::factory()->create([
            'email' => 'reseller@example.com',
            'password_hash' => Hash::make('password-that-is-long'),
        ]);

        $this->post(route('login'), [
            'email' => 'reseller@example.com',
            'password' => 'password-that-is-long',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($tenant, 'tenant');
    }

    public function test_a_tenant_can_log_in_with_remember_me(): void
    {
        $tenant = Tenant::factory()->create([
            'email' => 'reseller@example.com',
            'password_hash' => Hash::make('password-that-is-long'),
        ]);

        $this->post(route('login'), [
            'email' => 'reseller@example.com',
            'password' => 'password-that-is-long',
            'remember' => true,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($tenant, 'tenant');

        // The token has to actually persist — this is the write that failed.
        $this->assertNotNull($tenant->fresh()->remember_token);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        Tenant::factory()->create([
            'email' => 'reseller@example.com',
            'password_hash' => Hash::make('password-that-is-long'),
        ]);

        $this->post(route('login'), [
            'email' => 'reseller@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('tenant');
    }

    /**
     * The super-admin guard has the same inheritance, so it had the same
     * missing column — worth pinning even though its screens come later.
     */
    public function test_a_superadmin_remember_token_can_be_stored(): void
    {
        $superadmin = Superadmin::create([
            'username' => 'hiddenxcel',
            'password_hash' => Hash::make('password-that-is-long'),
        ]);

        Auth::guard('superadmin')->login($superadmin, true);

        $this->assertNotNull($superadmin->fresh()->remember_token);
    }
}
