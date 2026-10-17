<?php

namespace App\Services\Catalogue;

use App\Models\BotService;
use App\Models\CatalogueChange;
use App\Models\PricingRule;
use App\Models\ServicePriceHistory;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Panel\SmmProviderClient;
use App\Services\Payments\PricingCurrency;
use Illuminate\Support\Collection;

/**
 * Re-reads a panel and brings the reseller's catalogue back in line with it.
 *
 * Three things can have happened since the last sync, and they need different
 * answers:
 *
 *   - the cost moved      -> record it, and re-apply the markup rule if one
 *                            covers the service, so margin is preserved
 *   - the service vanished -> pause it, never delete it. The reseller's orders
 *                            reference it, and a panel dropping a service for
 *                            an hour is common; deleting on that basis would
 *                            lose the price they set and their history
 *   - the limits moved    -> follow them, or the bot accepts quantities the
 *                            panel will reject
 *
 * What it deliberately does NOT do is change a price with no rule behind it.
 * A reseller who set 5.00 by hand meant 5.00, and a sync that quietly moved it
 * would be the platform overruling them.
 */
class PanelSync
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * @return array{
     *     failed: bool,
     *     message: string,
     *     checked: int,
     *     costChanged: int,
     *     repriced: int,
     *     paused: int,
     *     limitsChanged: int,
     *     queued: int,
     * }
     */
    public function run(TenantPanel $panel): array
    {
        $response = SmmProviderClient::forPanel($panel)->getServices();

        if ($response->failed) {
            return $this->outcome(true, $response->message ?? 'Could not read that panel.');
        }

        // Keyed by the panel's own service id, which is what our rows store.
        $remote = (new Collection($response->get('services', [])))
            ->filter(fn ($service) => is_array($service) && isset($service['service']))
            ->keyBy(fn (array $service) => (string) $service['service']);

        $mine = BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('panel_id', $panel->id)
            ->get();

        $engine = PricingEngine::for($this->tenant);
        $rules = $engine->rules();

        $costChanged = 0;
        $repriced = 0;
        $paused = 0;
        $limitsChanged = 0;
        $queued = 0;

        // Off by default: the sync tells the reseller what moved and waits for
        // their word. On, it applies the change itself.
        $auto = (bool) $this->tenant->auto_catalogue_sync;

        foreach ($mine as $service) {
            $entry = $remote->get((string) $service->provider_service_id);

            if ($entry === null) {
                if ($service->status !== BotService::PAUSED) {
                    // Flagged as ours, so a later sync knows it may lift this
                    // pause without overruling the reseller. Paused at once
                    // either way: nobody should be sold a service that is gone.
                    $service->forceFill([
                        'status' => BotService::PAUSED,
                        'auto_paused' => true,
                        'last_synced_at' => now(),
                    ])->save();
                    $paused++;

                    $this->record($service, CatalogueChange::REMOVED, $auto, [
                        'note' => 'No longer on the panel',
                    ]);

                    if (! $auto) {
                        $queued++;
                    }
                }

                continue;
            }

            // Back on the panel: a removal still waiting for an answer is moot.
            $this->forget($service, CatalogueChange::REMOVED);

            $newCost = $this->normaliseCost($entry['rate'] ?? null);

            // The panel's own currency, brought to the shop's, so the margin
            // arithmetic everywhere else compares like with like.
            if ($newCost !== null) {
                $newCost = PricingCurrency::costFromPanel($newCost, $panel->balance_currency, $this->tenant);
            }
            $oldCost = $service->cost_price === null ? null : (string) $service->cost_price;
            $costMoved = $newCost !== null && ($oldCost === null || bccomp($newCost, $oldCost, 4) !== 0);

            if ($costMoved) {
                $costChanged++;

                // A rule means the reseller asked for a margin, not a price —
                // so the price follows the cost. Without one, their own price
                // stands and only the cost is updated.
                $ruled = $newCost === null ? null : $this->priceForNewCost($service, $newCost, $engine, $rules);
                $reason = ServicePriceHistory::RULE;
                $note = 'Panel cost changed; markup rule re-applied';

                // No rule, but the new cost would put the price underwater.
                // A hand-set price stands while it still earns something;
                // once it would sell at a loss, keep the margin it had.
                if ($ruled === null && $newCost !== null) {
                    $ruled = $this->protectedPrice($service, $oldCost, $newCost);
                    $reason = ServicePriceHistory::SYNC;
                    $note = 'Panel cost rose above the price; margin kept';
                }

                $proposed = $ruled !== null && bccomp($ruled, (string) $service->my_price, 4) !== 0 ? $ruled : null;

                if (! $auto) {
                    // Only ask when the answer is new: a change the reseller
                    // already dismissed stays dismissed until the cost moves again.
                    if ($this->queueCost($service, $oldCost, $newCost, $proposed)) {
                        $queued++;
                    }
                } elseif ($proposed !== null) {
                    $before = (string) $service->my_price;
                    $engine->setPrice($service, $proposed, $reason, $note, newCost: $newCost);
                    $repriced++;
                    $this->logApplied($service, $oldCost, $newCost, $before, $proposed);
                } else {
                    $this->logApplied($service, $oldCost, $newCost, (string) $service->my_price, null);
                    $service->forceFill(['cost_price' => $newCost])->save();

                    ServicePriceHistory::withoutTenantScope()->create([
                        'tenant_id' => $service->tenant_id,
                        'service_id' => $service->id,
                        'reason' => ServicePriceHistory::SYNC,
                        'old_price' => $service->my_price,
                        'new_price' => $service->my_price,
                        'old_cost' => $oldCost,
                        'new_cost' => $newCost,
                        'note' => 'Panel cost changed',
                    ]);
                }
            } else {
                // Back to what we hold, so nothing is left waiting or dismissed.
                $this->forget($service, CatalogueChange::COST);
            }

            $limits = $this->limitsFrom($entry);

            if ($limits !== null && ($limits['min'] !== $service->min_quantity
                || $limits['max'] !== $service->max_quantity)) {
                $service->forceFill([
                    'min_quantity' => $limits['min'],
                    'max_quantity' => $limits['max'],
                ])->save();
                $limitsChanged++;
            }

            // A service that came back is no longer missing. Only lift the
            // pause this sync caused — a reseller who paused it deliberately
            // would not thank us for switching it back on.
            $updates = ['last_synced_at' => now(), 'synced_cost_price' => $newCost];

            if ($service->wasAutoPaused()) {
                $updates['status'] = BotService::ACTIVE;
                $updates['auto_paused'] = false;
            }

            $service->forceFill($updates)->save();
        }

        return $this->outcome(
            false,
            $this->describe($mine->count(), $costChanged, $repriced, $paused, $limitsChanged, $queued, $auto),
            checked: $mine->count(),
            costChanged: $costChanged,
            repriced: $repriced,
            paused: $paused,
            limitsChanged: $limitsChanged,
            queued: $queued,
        );
    }

    /**
     * The price a rule would produce for a cost that has not been saved yet.
     *
     * @param  Collection<int, PricingRule>  $rules
     */
    private function priceForNewCost(
        BotService $service,
        string $newCost,
        PricingEngine $engine,
        Collection $rules,
    ): ?string {
        $rule = $rules->first(fn ($candidate) => $candidate->matches($service));

        return $rule === null ? null : $engine->applyRule($rule, $newCost);
    }

    /**
     * The price that keeps the old markup ratio, only when the current price
     * would no longer cover the new cost. Null leaves the price alone.
     */
    private function protectedPrice(BotService $service, ?string $oldCost, string $newCost): ?string
    {
        $price = (string) $service->my_price;

        if (bccomp($price, $newCost, 4) >= 0) {
            return null;
        }

        if ($oldCost === null || bccomp($oldCost, '0', 4) <= 0 || bccomp($price, $oldCost, 4) < 0) {
            // No healthy margin to copy; the reseller will see it flagged.
            return null;
        }

        $ratio = bcdiv($price, $oldCost, 6);

        return bcadd(bcmul($newCost, $ratio, 6), '0', 4);
    }

    /**
     * Leave a note for the reseller. With auto-sync on the change has already
     * happened and this is the record; otherwise it waits for an answer.
     *
     * @param  array<string, mixed>  $extra
     */
    private function record(BotService $service, string $kind, bool $applied, array $extra = []): CatalogueChange
    {
        $existing = CatalogueChange::withoutTenantScope()
            ->where('service_id', $service->id)
            ->where('kind', $kind)
            ->where('status', CatalogueChange::PENDING)
            ->first();

        $attributes = $extra + [
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'panel_id' => $service->panel_id,
            'provider_service_id' => $service->provider_service_id,
            'service_name' => $service->name,
            'kind' => $kind,
            'status' => $applied ? CatalogueChange::APPLIED : CatalogueChange::PENDING,
            'resolved_at' => $applied ? now() : null,
        ];

        if ($existing !== null && ! $applied) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        return CatalogueChange::withoutTenantScope()->create($attributes);
    }

    /** A cost change for the reseller to allow. False when it was already dismissed. */
    private function queueCost(BotService $service, ?string $oldCost, string $newCost, ?string $proposed): bool
    {
        $dismissed = CatalogueChange::withoutTenantScope()
            ->where('service_id', $service->id)
            ->where('kind', CatalogueChange::COST)
            ->where('status', CatalogueChange::DISMISSED)
            ->where('new_cost', $newCost)
            ->exists();

        if ($dismissed) {
            return false;
        }

        // The cost moved again since a dismissal: that answer no longer applies.
        CatalogueChange::withoutTenantScope()
            ->where('service_id', $service->id)
            ->where('kind', CatalogueChange::COST)
            ->where('status', CatalogueChange::DISMISSED)
            ->delete();

        $this->record($service, CatalogueChange::COST, false, [
            'old_cost' => $oldCost,
            'new_cost' => $newCost,
            'old_price' => $service->my_price,
            'proposed_price' => $proposed,
        ]);

        return true;
    }

    private function logApplied(BotService $service, ?string $oldCost, string $newCost, string $oldPrice, ?string $newPrice): void
    {
        $this->record($service, CatalogueChange::COST, true, [
            'old_cost' => $oldCost,
            'new_cost' => $newCost,
            'old_price' => $oldPrice,
            'proposed_price' => $newPrice,
            'note' => 'Applied by auto-sync',
        ]);
    }

    /** Drop waiting or dismissed notes of one kind, once they stop being true. */
    private function forget(BotService $service, string $kind): void
    {
        CatalogueChange::withoutTenantScope()
            ->where('service_id', $service->id)
            ->where('kind', $kind)
            ->whereIn('status', [CatalogueChange::PENDING, CatalogueChange::DISMISSED])
            ->delete();
    }

    private function normaliseCost(mixed $rate): ?string
    {
        if ($rate === null || ! is_numeric($rate)) {
            return null;
        }

        return bcadd((string) $rate, '0', 4);
    }

    /** @return array{min: int, max: int}|null */
    private function limitsFrom(array $entry): ?array
    {
        if (! isset($entry['min']) && ! isset($entry['max'])) {
            return null;
        }

        $min = max(1, (int) ($entry['min'] ?? 1));
        $max = max($min, (int) ($entry['max'] ?? 100000));

        return ['min' => $min, 'max' => $max];
    }

    private function describe(
        int $checked,
        int $costChanged,
        int $repriced,
        int $paused,
        int $limitsChanged,
        int $queued = 0,
        bool $auto = false,
    ): string {
        if ($checked === 0) {
            return 'Nothing to sync — no services are imported from that panel yet.';
        }

        $parts = [];

        if ($costChanged > 0) {
            $parts[] = "{$costChanged} ".str('cost')->plural($costChanged).' changed';
        }

        if ($repriced > 0) {
            $parts[] = "{$repriced} repriced by your rules";
        }

        if ($paused > 0) {
            $parts[] = "{$paused} paused (no longer on the panel)";
        }

        if ($limitsChanged > 0) {
            $parts[] = "{$limitsChanged} had new limits";
        }

        if ($queued > 0) {
            $parts[] = "{$queued} waiting for your approval";
        }

        if ($parts === []) {
            return "Checked {$checked} ".str('service')->plural($checked).' — nothing had changed.';
        }

        return "Checked {$checked}: ".implode(', ', $parts).'.';
    }

    private function outcome(
        bool $failed,
        string $message,
        int $checked = 0,
        int $costChanged = 0,
        int $repriced = 0,
        int $paused = 0,
        int $limitsChanged = 0,
        int $queued = 0,
    ): array {
        return compact('failed', 'message', 'checked', 'costChanged', 'repriced', 'paused', 'limitsChanged', 'queued');
    }
}
