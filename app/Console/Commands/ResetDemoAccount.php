<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoAccount;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the public demo account from the seeder.
 *
 * This deletes a tenant and everything hanging off it, so it is written to be
 * impossible to point at the wrong one. Three things have to line up: an email
 * configured in config/demo.php, a tenant that matches it, and that address
 * belonging to the demo rather than a customer. Any doubt and it does nothing.
 *
 * The delete relies on the cascade already in the schema — 23 tables carry
 * `constrained('tenants')->cascadeOnDelete()`, so removing the row removes the
 * orders, customers, panels, keys and messages with it. Listing those tables
 * here would be a second copy of the schema that goes stale the first time
 * someone adds a table and forgets this file.
 *
 * The account is read-only in the first place (see LockDemoAccount) — this is
 * the second line, clearing anything that did get through and putting the
 * fixture back the way a visitor expects to find it.
 */
class ResetDemoAccount extends Command
{
    protected $signature = 'demo:reset {--force : Skip the confirmation}';

    protected $description = 'Delete and rebuild the public demo account';

    public function handle(): int
    {
        if (! DemoAccount::isConfigured()) {
            // Not an error: an install without a demo is the normal case, and
            // a scheduled command must not fail the run for it.
            $this->info('No demo account is configured (demo.email is empty). Nothing to do.');

            return self::SUCCESS;
        }

        $email = DemoAccount::email();
        $tenant = DemoAccount::tenant();

        if ($tenant === null) {
            // The account may simply not exist yet — a fresh database, or a
            // previous run interrupted between the delete and the seed. The
            // seeder creates it, so this is recoverable rather than fatal.
            $this->warn("No tenant found for {$email}; seeding a fresh one.");

            $this->seed();

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete and rebuild {$email}?", true)) {
            $this->info('Left alone.');

            return self::SUCCESS;
        }

        // One transaction: a delete that committed without the reseed
        // following it would leave the published login pointing at nothing.
        DB::transaction(function () use ($tenant) {
            $tenant->delete();

            $this->seed();
        });

        $this->info("Demo account rebuilt: {$email}");

        return self::SUCCESS;
    }

    /** Quietly — the seeder's own output is noise on a scheduled run. */
    private function seed(): void
    {
        $seeder = new DemoSeeder;
        $seeder->setContainer(app());

        $seeder->run();
    }
}
