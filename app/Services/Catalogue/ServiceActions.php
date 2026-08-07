<?php

namespace App\Services\Catalogue;

use App\Models\BotService;
use App\Models\ServicePriceHistory;
use App\Models\Tenant;
use App\Services\Customers\ActionOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a reseller may do to a service, and doing it.
 *
 * Price changes never happen here directly — they go through PricingEngine,
 * which is the only thing that writes my_price, so the history cannot develop
 * gaps. That is the rule that makes the log worth reading.
 */
class ServiceActions
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * Edit a service's details.
     *
     * `provider_service_id` and `panel_id` are not editable: together they are
     * what ties this row to the panel and to its orders, and changing either
     * would point the service at something else while keeping its history.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(BotService $service, array $attributes): ActionOutcome
    {
        $price = $attributes['my_price'] ?? null;

        $service->fill([
            'name' => $attributes['name'] ?? $service->name,
            'description' => $attributes['description'] ?? null,
            'platform' => $attributes['platform'] ?? $service->platform,
            'category' => $attributes['category'] ?? null,
            'unit_label' => $attributes['unit_label'] ?? $service->unit_label,
            'link_instructions' => $attributes['link_instructions'] ?? null,
            'min_quantity' => max(1, (int) ($attributes['min_quantity'] ?? $service->min_quantity)),
            'max_quantity' => max(
                (int) ($attributes['min_quantity'] ?? $service->min_quantity),
                (int) ($attributes['max_quantity'] ?? $service->max_quantity),
            ),
        ]);

        if (isset($attributes['cost_price'])) {
            $service->cost_price = $attributes['cost_price'];
        }

        $service->save();

        // The price is applied separately and only when it moved, so an edit
        // that changed the name does not leave a price-change row behind.
        if ($price !== null && bccomp((string) $price, (string) $service->my_price, 4) !== 0) {
            PricingEngine::for($this->tenant)->setPrice(
                $service,
                (string) $price,
                ServicePriceHistory::MANUAL,
                'Edited by hand',
            );
        }

        return ActionOutcome::ok('Service updated.');
    }

    /**
     * Set a service's status.
     *
     * Clears `auto_paused` on any deliberate change: once the reseller has had
     * an opinion, the sync must not overrule it by lifting a pause it thinks
     * it owns.
     */
    public function setStatus(BotService $service, string $status): ActionOutcome
    {
        if (! in_array($status, BotService::STATUSES, true)) {
            return ActionOutcome::failed('That is not a status.');
        }

        $service->forceFill(['status' => $status, 'auto_paused' => false])->save();

        return ActionOutcome::ok(match ($status) {
            BotService::ACTIVE => 'Service is live again.',
            BotService::HIDDEN => 'Hidden. Customers will not see it at all.',
            BotService::PAUSED => 'Paused. Customers can see it but cannot order it.',
        });
    }

    /**
     * Copy a service.
     *
     * Copies land hidden and unfeatured. A duplicate is nearly always the
     * starting point for a variant the reseller is about to edit, and two
     * identical live services confuse a customer choosing between them.
     */
    public function duplicate(BotService $service): ActionOutcome
    {
        $copy = $service->replicate([
            'created_at',
            'updated_at',
            'last_synced_at',
            'synced_cost_price',
        ]);

        $copy->name = mb_substr($service->name.' (copy)', 0, 190);
        $copy->status = BotService::HIDDEN;
        $copy->featured = false;
        $copy->auto_paused = false;
        $copy->save();

        ServicePriceHistory::withoutTenantScope()->create([
            'tenant_id' => $copy->tenant_id,
            'service_id' => $copy->id,
            'reason' => ServicePriceHistory::IMPORT,
            'old_price' => null,
            'new_price' => $copy->my_price,
            'old_cost' => null,
            'new_cost' => $copy->cost_price,
            'note' => "Copied from #{$service->id}",
        ]);

        return ActionOutcome::ok('Duplicated. The copy is hidden until you publish it.', [
            'id' => $copy->id,
        ]);
    }

    public function toggleFlag(BotService $service, string $flag, bool $value): ActionOutcome
    {
        if (! in_array($flag, ['featured', 'requires_approval'], true)) {
            return ActionOutcome::failed('Unknown setting.');
        }

        $service->forceFill([$flag => $value])->save();

        return ActionOutcome::ok('Saved.');
    }

    /**
     * Delete a service.
     *
     * Allowed even with orders behind it, unlike a customer: `bot_orders`
     * stores the service NAME and the panel's id on the order row itself, so
     * the order history stays complete and readable after the catalogue entry
     * is gone. Hiding is still the better move, and the UI says so.
     */
    public function delete(BotService $service): ActionOutcome
    {
        $service->delete();

        return ActionOutcome::ok('Service deleted. Past orders keep their details.');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, BotService>
     */
    public function servicesByIds(array $ids, int $limit): Collection
    {
        return BotService::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('id', array_slice($ids, 0, $limit))
            ->get();
    }

    /**
     * Bulk status, feature and delete. Bulk pricing is separate — it needs a
     * preview step, and lives in PricingEngine.
     *
     * @param  list<int>  $ids
     * @return array{done: int, skipped: int, message: string}
     */
    public function runBulk(string $action, array $ids): array
    {
        $services = $this->servicesByIds($ids, BulkServiceAction::MAX_SELECTION);

        if ($action === BulkServiceAction::DELETE) {
            $count = $services->count();

            DB::transaction(fn () => $services->each->delete());

            return [
                'done' => $count,
                'skipped' => 0,
                'message' => $count.' '.str('service')->plural($count).' deleted.',
            ];
        }

        $done = 0;
        $skipped = 0;

        foreach ($services as $service) {
            $changed = match ($action) {
                BulkServiceAction::ACTIVATE => $this->applyStatus($service, BotService::ACTIVE),
                BulkServiceAction::HIDE => $this->applyStatus($service, BotService::HIDDEN),
                BulkServiceAction::PAUSE => $this->applyStatus($service, BotService::PAUSED),
                BulkServiceAction::FEATURE => $this->applyFlag($service, 'featured', true),
                BulkServiceAction::UNFEATURE => $this->applyFlag($service, 'featured', false),
                default => false,
            };

            $changed ? $done++ : $skipped++;
        }

        return [
            'done' => $done,
            'skipped' => $skipped,
            'message' => $this->describe($action, $done, $skipped),
        ];
    }

    private function applyStatus(BotService $service, string $status): bool
    {
        if ($service->status === $status) {
            return false;
        }

        $service->forceFill(['status' => $status, 'auto_paused' => false])->save();

        return true;
    }

    private function applyFlag(BotService $service, string $flag, bool $value): bool
    {
        if ((bool) $service->{$flag} === $value) {
            return false;
        }

        $service->forceFill([$flag => $value])->save();

        return true;
    }

    private function describe(string $action, int $done, int $skipped): string
    {
        $verb = match ($action) {
            BulkServiceAction::ACTIVATE => 'made live',
            BulkServiceAction::HIDE => 'hidden',
            BulkServiceAction::PAUSE => 'paused',
            BulkServiceAction::FEATURE => 'featured',
            BulkServiceAction::UNFEATURE => 'unfeatured',
            default => 'updated',
        };

        if ($done === 0) {
            return "Nothing to do — all {$skipped} were already {$verb}.";
        }

        $message = $done.' '.str('service')->plural($done)." {$verb}";

        return $skipped > 0 ? "{$message}, {$skipped} already were." : "{$message}.";
    }
}
