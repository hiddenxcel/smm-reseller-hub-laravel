<?php

namespace App\Console\Commands;

use App\Services\Payments\ReconcilePayments as Reconcile;
use Illuminate\Console\Command;

/**
 * Asks the gateways about payments that are still waiting, and credits the
 * ones that turn out to have been paid.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--tenant= : Only this reseller\'s payments}';

    protected $description = 'Ask gateways whether waiting payments were paid, and credit those that were';

    public function handle(Reconcile $reconcile): int
    {
        $result = $reconcile->run($this->option('tenant') ? (int) $this->option('tenant') : null);

        $this->info("Asked about {$result['checked']}, credited {$result['credited']}.");

        return self::SUCCESS;
    }
}