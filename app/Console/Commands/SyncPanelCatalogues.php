<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Catalogue\PanelSync;
use Illuminate\Console\Command;

/**
 * Re-reads every connected panel so a cost that moved there moves here too.
 *
 * Until this existed the sync ran only when a reseller pressed its button, so a
 * panel raising a price left the bot selling at the old one — below cost —
 * until somebody noticed.
 */
class SyncPanelCatalogues extends Command
{
    protected $signature = 'catalogue:sync
                            {--tenant= : Sync only this tenant\'s panels}';

    protected $description = 'Bring each reseller\'s prices and limits in line with their panels';

    public function handle(): int
    {
        $panels = TenantPanel::withoutTenantScope()
            ->whereIn('tenant_id', Tenant::query()->where('status', 'active')->select('id'))
            ->when($this->option('tenant'), fn ($query, $id) => $query->where('tenant_id', $id))
            ->with('tenant')
            ->get();

        $repriced = 0;

        foreach ($panels as $panel) {
            // One panel failing must not stop the ones after it.
            try {
                $result = PanelSync::for($panel->tenant)->run($panel);
            } catch (\Throwable $e) {
                $this->error("Panel {$panel->id} ({$panel->name}): {$e->getMessage()}");

                continue;
            }

            if ($result['failed']) {
                $this->warn("Panel {$panel->id} ({$panel->name}): {$result['message']}");

                continue;
            }

            $repriced += $result['repriced'];
        }

        $this->info("Synced {$panels->count()} panel(s), {$repriced} repriced.");

        return self::SUCCESS;
    }
}
