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

        Superadmin::create([
            'username' => $username,
            'password_hash' => Hash::make($password),
        ]);

        $this->command->info("Super-admin '{$username}' created.");
    }
}
