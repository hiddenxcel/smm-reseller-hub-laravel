<?php

namespace App\Services\Panel;

use App\Models\TenantPanel;
use App\Notifications\PanelBalanceLow;
use App\Notifications\PanelRecovered;
use App\Notifications\PanelWentDown;
use Illuminate\Support\Carbon;

/**
 * Checks that a reseller's panel is still answering, and tells them when it
 * stops.
 *
 * A panel that has gone down is the one failure a reseller cannot see: the
 * dashboard keeps showing yesterday's balance, the bot keeps taking orders,
 * and the first sign of trouble is a customer complaining. The `balance` call
 * is what does the checking — it is the cheapest action the SMM API has, it
 * needs no arguments, and it fails in exactly the ways that matter (bad key,
 * dead host, panel returning HTML).
 *
 * Deliberately does NOT pause the panel's services. The submit job already
 * retries three times over a minute, and panels are flaky far more often than
 * they are dead — pausing on a blip would stop a reseller's sales for a
 * problem that fixed itself. The reseller is told; the decision stays theirs.
 */
class PanelHealth
{
    /**
     * Check one panel, record what came back, and notify on a change of state.
     *
     * Notifying only on the *transition* is what keeps this usable: a panel
     * that is down stays down for hours, and a check every five minutes would
     * otherwise send a reseller twelve identical emails an hour until they
     * stopped reading any of them.
     */
    public function check(TenantPanel $panel): PanelHealthResult
    {
        $wasHealthy = $panel->status !== 'error';

        $response = SmmProviderClient::forPanel($panel)->checkBalance();

        if ($response->failed) {
            $panel->update([
                'status' => 'error',
                'last_checked_at' => Carbon::now(),
            ]);

            // Only on the way down. The balance is left as it was: the last
            // figure we genuinely saw is more useful than null, as long as
            // `last_checked_at` says how old it is.
            if ($wasHealthy) {
                $panel->tenant?->notify(new PanelWentDown($panel, $response->message ?? 'Could not reach the panel'));
            }

            return PanelHealthResult::down($response->message ?? 'Could not reach the panel', $wasHealthy);
        }

        $balance = $response->data['balance'] ?? null;

        // Read before the update, so "was it already low?" is answered against
        // the previous check rather than against the figure we just wrote.
        $wasLow = $panel->isLowOnFunds();
        $threshold = $panel->lowBalanceThreshold();

        $panel->update([
            'status' => 'active',
            'last_checked_at' => Carbon::now(),
            'last_balance' => $balance !== null ? (float) $balance : $panel->last_balance,
            'balance_currency' => $response->data['currency'] ?? $panel->balance_currency,
        ]);

        if (! $wasHealthy) {
            $panel->tenant?->notify(new PanelRecovered($panel));
        }

        // On the crossing only. A panel sitting below its threshold for a week
        // is one email, not one every five minutes — and a reseller who reads
        // the first is not helped by the next two thousand.
        if ($balance !== null && (float) $balance <= $threshold && ! $wasLow) {
            $panel->tenant?->notify(new PanelBalanceLow($panel, (float) $balance));
        }

        return PanelHealthResult::up(
            balance: $balance !== null ? (float) $balance : null,
            recovered: ! $wasHealthy,
        );
    }
}
