<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Panel\PanelHealth;
use Illuminate\Console\Command;

/**
 * Polls every connected panel and reports what changed.
 *
 * Runs on a schedule (see routes/console.php). Suspended resellers are skipped
 * — their bots are not selling, so a panel of theirs going down is not an
 * event anyone needs an email about.
 *
 * Panels are checked one at a time rather than concurrently. The call is a few
 * hundred milliseconds and the volume is one request per panel per run; making
 * this parallel would trade a real increase in complexity for time nobody is
 * waiting on.
 */
class CheckPanelHealth extends Command
{
    protected $signature = 'panels:check
                            {--tenant= : Check only this tenant\'s panels}';

    protected $description = 'Check that every connected panel is still answering';

    public function handle(PanelHealth $health): int
    {
        $panels = TenantPanel::withoutTenantScope()
            ->whereIn(
                'tenant_id',
                Tenant::query()->where('status', 'active')->select('id'),
            )
            ->when(
                $this->option('tenant'),
                fn ($query, $tenantId) => $query->where('tenant_id', $tenantId),
            )
            ->with('tenant')
            ->get();

        if ($panels->isEmpty()) {
            $this->info('No panels to check.');

            return self::SUCCESS;
        }

        $changed = 0;
        $down = 0;

        foreach ($panels as $panel) {
            // One panel's failure is not the run's failure. An exception here
            // — a malformed URL, a DNS error the client did not catch — would
            // otherwise stop every panel after it from being checked at all.
            try {
                $result = $health->check($panel);
            } catch (\Throwable $e) {
                $this->error("Panel {$panel->id} ({$panel->name}): {$e->getMessage()}");

                continue;
            }

            if (! $result->healthy) {
                $down++;
            }

            if ($result->changed) {
                $changed++;

                $this->line($result->healthy
                    ? "Recovered: {$panel->name} (tenant {$panel->tenant_id})"
                    : "Down: {$panel->name} (tenant {$panel->tenant_id}) — {$result->message}");
            }
        }

        $this->info(sprintf(
            'Checked %d panel%s: %d down, %d changed state.',
            $panels->count(),
            $panels->count() === 1 ? '' : 's',
            $down,
            $changed,
        ));

        return self::SUCCESS;
    }
}
