<?php

namespace Database\Seeders;

use App\Models\Superadmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the platform super-admin. Credentials come from the environment,
 * never from source — set SUPERADMIN_USERNAME and SUPERADMIN_PASSWORD before
 * running, e.g.
 *
 *   SUPERADMIN_USERNAME=hidden SUPERADMIN_PASSWORD='...' php artisan db:seed --class=SuperadminSeeder
 */
class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('SUPERADMIN_USERNAME');
        $password = env('SUPERADMIN_PASSWORD');

        if (blank($username) || blank($password)) {
            $this->command->warn('Skipped: set SUPERADMIN_USERNAME and SUPERADMIN_PASSWORD to seed a super-admin.');

            return;
        }

        if (Superadmin::where('username', $username)->exists()) {
            $this->command->warn("Super-admin '{$username}' already exists — not modified.");

            return;
        }

        // The first admin is the owner: the console has to start with someone
        // who can create the others.
        Superadmin::create([
            'username' => $username,
            'name' => env('SUPERADMIN_NAME') ?: $username,
            'email' => env('SUPERADMIN_EMAIL'),
            'password_hash' => Hash::make($password),
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->command->info("Super-admin '{$username}' created as owner.");
    }
}
