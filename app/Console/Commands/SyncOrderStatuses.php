<?php

namespace App\Console\Commands;

use App\Services\Orders\SyncOrderStatuses as Sync;
use Illuminate\Console\Command;

/**
 * Brings every open order up to date with its provider, and refunds the
 * customers of orders the provider cancelled or only part-delivered.
 */
class SyncOrderStatuses extends Command
{
    protected $signature = 'orders:sync {--tenant= : Only this reseller\'s orders}';

    protected $description = 'Check open orders with their providers and refund cancelled or partial ones';

    public function handle(Sync $sync): int
    {
        $result = $sync->run($this->option('tenant') ? (int) $this->option('tenant') : null);

        $this->info("Checked {$result['checked']}, updated {$result['updated']}, refunded {$result['refunded']}.");

        return self::SUCCESS;
    }
}