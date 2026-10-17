<?php

namespace App\Services\Catalogue;

use App\Models\BotService;
use App\Models\CatalogueChange;
use App\Models\ServicePriceHistory;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The reseller's answers to what the sync noticed.
 *
 * Allowing a cost change takes the new cost and the price the sync proposed in
 * one step, through PricingEngine so the history records it. Allowing a removal
 * deletes the service (past orders keep their own copy of its name). Dismissing
 * leaves everything exactly as it is.
 */
class CatalogueChanges
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** @return Collection<int, CatalogueChange> */
    public function pending(): Collection
    {
        return CatalogueChange::query()
            ->where('status', CatalogueChange::PENDING)
            ->with('panel:id,name')
            ->orderBy('kind')
            ->orderBy('id')
            ->get();
    }

    public function pendingCount(): int
    {
        return CatalogueChange::query()->where('status', CatalogueChange::PENDING)->count();
    }

    public function approve(CatalogueChange $change): bool
    {
        if ($change->status !== CatalogueChange::PENDING) {
            return false;
        }

        DB::transaction(function () use ($change) {
            $service = $change->service_id === null
                ? null
                : BotService::query()->whereKey($change->service_id)->first();

            if ($service !== null) {
                $change->kind === CatalogueChange::REMOVED
                    ? $this->removeService($service)
                    : $this->applyCost($service, $change);
            }

            $change->forceFill(['status' => CatalogueChange::APPLIED, 'resolved_at' => now()])->save();
        });

        return true;
    }

    public function dismiss(CatalogueChange $change): bool
    {
        if ($change->status !== CatalogueChange::PENDING) {
            return false;
        }

        $change->forceFill(['status' => CatalogueChange::DISMISSED, 'resolved_at' => now()])->save();

        return true;
    }

    /** @return int how many were answered */
    public function resolveAll(bool $approve): int
    {
        $done = 0;

        foreach ($this->pending() as $change) {
            if ($approve ? $this->approve($change) : $this->dismiss($change)) {
                $done++;
            }
        }

        return $done;
    }

    private function applyCost(BotService $service, CatalogueChange $change): void
    {
        $newCost = (string) $change->new_cost;
        $oldCost = $service->cost_price === null ? null : (string) $service->cost_price;
        $proposed = $change->proposed_price === null ? null : (string) $change->proposed_price;

        if ($proposed !== null && bccomp($proposed, (string) $service->my_price, 4) !== 0) {
            PricingEngine::for($this->tenant)->setPrice(
                $service,
                $proposed,
                ServicePriceHistory::SYNC,
                'Panel cost change allowed',
                newCost: $newCost,
            );

            return;
        }

        $service->forceFill(['cost_price' => $newCost])->save();

        ServicePriceHistory::withoutTenantScope()->create([
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'reason' => ServicePriceHistory::SYNC,
            'old_price' => $service->my_price,
            'new_price' => $service->my_price,
            'old_cost' => $oldCost,
            'new_cost' => $newCost,
            'note' => 'Panel cost change allowed',
        ]);
    }

    private function removeService(BotService $service): void
    {
        ServiceActions::for($this->tenant)->delete($service);
    }
}
