<?php

namespace App\Console\Commands;

use App\Models\BotService;
use App\Services\Catalogue\ServiceFeatures;
use Illuminate\Console\Command;

/**
 * Fills in the drop and refill lines of services that were imported before
 * those lines existed, from what their names already promise.
 *
 * Only blanks are filled, and only with what the name says outright, so a line
 * a reseller has set — or a name that says nothing — is left exactly as it is.
 */
class GuessServiceFeatures extends Command
{
    protected $signature = 'services:guess-features {--tenant= : Only this reseller\'s services}';

    protected $description = 'Fill blank drop/refill lines from what service names promise';

    public function handle(): int
    {
        $filled = 0;

        BotService::withoutTenantScope()
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->where('tenant_id', $tenant))
            ->where(fn ($q) => $q->whereNull('drop_info')->orWhereNull('refill_info'))
            ->chunkById(200, function ($services) use (&$filled) {
                foreach ($services as $service) {
                    $guess = ServiceFeatures::guess($service->name);
                    $fill = [];

                    foreach (['drop_info', 'refill_info'] as $column) {
                        if (blank($service->{$column}) && $guess[$column] !== null) {
                            $fill[$column] = $guess[$column];
                        }
                    }

                    if ($fill !== []) {
                        $service->update($fill);
                        $filled++;
                    }
                }
            });

        $this->info("Filled in {$filled} service(s).");

        return self::SUCCESS;
    }
}