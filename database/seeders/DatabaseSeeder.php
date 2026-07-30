<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            SuperadminSeeder::class,
        ]);

        // ResponseTemplateSeeder (18 keys x 3 langs) is ported alongside the
        // bot handlers, since the template keys are defined by those handlers.
    }
}
