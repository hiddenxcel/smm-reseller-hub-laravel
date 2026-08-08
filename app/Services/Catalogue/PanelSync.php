<?php

namespace App\Services\Catalogue;

use App\Models\BotService;
use App\Models\PricingRule;
use App\Models\ServicePriceHistory;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Panel\SmmProviderClient;
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

        foreach ($mine as $service) {
            $entry = $remote->get((string) $service->provider_service_id);

            if ($entry === null) {
                if ($service->status !== BotService::PAUSED) {
                    // Flagged as ours, so a later sync knows it may lift this
                    // pause without overruling the reseller.
                    $service->forceFill([
                        'status' => BotService::PAUSED,
                        'auto_paused' => true,
                        'last_synced_at' => now(),
                    ])->save();
                    $paused++;
                }

                continue;
            }

            $newCost = $this->normaliseCost($entry['rate'] ?? null);
            $oldCost = $service->cost_price === null ? null : (string) $service->cost_price;
            $costMoved = $newCost !== null && ($oldCost === null || bccomp($newCost, $oldCost, 4) !== 0);

            if ($costMoved) {
                $costChanged++;

                // A rule means the reseller asked for a margin, not a price —
                // so the price follows the cost. Without one, their own price
                // stands and only the cost is updated.
                $ruled = $newCost === null ? null : $this->priceForNewCost($service, $newCost, $engine, $rules);

                if ($ruled !== null && bccomp($ruled, (string) $service->my_price, 4) !== 0) {
                    $engine->setPrice(
                        $service,
                        $ruled,
                        ServicePriceHistory::RULE,
                        'Panel cost changed; markup rule re-applied',
                        newCost: $newCost,
                    );
                    $repriced++;
                } else {
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
            $this->describe($mine->count(), $costChanged, $repriced, $paused, $limitsChanged),
            checked: $mine->count(),
            costChanged: $costChanged,
            repriced: $repriced,
            paused: $paused,
            limitsChanged: $limitsChanged,
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
    ): array {
        return compact('failed', 'message', 'checked', 'costChanged', 'repriced', 'paused', 'limitsChanged');
    }
}
