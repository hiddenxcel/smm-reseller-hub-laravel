<?php

use Database\Seeders\AssistantKnowledgeSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The assistant's opening answers, on every environment that migrates.
 *
 * A migration rather than a seeder call in the deploy script, because deploy
 * runs migrations and nothing else. Without this, production comes up with the
 * widget switched on and a knowledge base holding nothing — every question
 * goes to the model, every answer costs money, and the ones we care most about
 * getting right are exactly the ones nobody wrote down.
 *
 * The seeder is idempotent on the question, so this is safe to run against a
 * database that already has these rows: it refreshes the wording rather than
 * duplicating it. It does not overwrite an answer somebody edited in the
 * console unless the question text still matches — and if it does match, the
 * console edit is the one that gets replaced. Worth knowing before editing
 * these paragraphs on a live site.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AssistantKnowledgeSeeder)->run();
    }

    /**
     * Deliberately empty.
     *
     * Rolling this back would delete answers an owner may since have edited or
     * added to, and losing those to a rollback of an unrelated migration is a
     * far worse outcome than a few extra rows.
     */
    public function down(): void {}
};
