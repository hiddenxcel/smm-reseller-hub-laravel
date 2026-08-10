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
            // The opening blog posts. Idempotent on the slug, so running the
            // seeders on a live database refreshes them rather than
            // duplicating — which is what makes it safe to edit a post here
            // and re-seed.
            BlogSeeder::class,
            // What the website assistant may say. Idempotent on the question,
            // for the same reason as the posts above.
            AssistantKnowledgeSeeder::class,
        ]);

        // ResponseTemplateSeeder (18 keys x 3 langs) is ported alongside the
        // bot handlers, since the template keys are defined by those handlers.
    }
}
