<?php

namespace App\Services\Admin;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\Pricing;

/**
 * The price list, as the console edits it.
 *
 * One rule dominates everything here: **changing a plan never changes what
 * anyone is already paying.** A subscription records its own ends_at, and
 * ActivatePurchase priced it at the moment of purchase — so a price rise
 * applies to the next renewal, not retroactively to the reseller who paid last
 * week. Nothing in this class touches the subscriptions table, and that is
 * deliberate rather than incidental.
 */
class PlanActions
{
    public function __construct(private Plan $plan) {}

    public static function for(Plan $plan): self
    {
        return new self($plan);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes): Plan
    {
        $plan = Plan::create($attributes);

        AdminAudit::record('plans.create', [
            'plan_id' => $plan->id,
            'code' => $plan->code,
            'service' => $plan->service_key->value,
            'monthly' => (float) $plan->price_monthly,
        ]);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): void
    {
        $before = $this->plan->only(array_keys($attributes));

        $this->plan->update($attributes);

        AdminAudit::record('plans.update', [
            'plan_id' => $this->plan->id,
            'code' => $this->plan->code,
            'before' => $before,
            'after' => $attributes,
            // How many resellers keep the old price until they renew. Recorded
            // because it is the first question asked after a price change.
            'live_subscriptions' => $this->liveCount(),
        ]);
    }

    /**
     * Take a plan off sale without deleting it.
     *
     * Deleting would orphan the subscription_payments and subscriptions rows
     * that point at it, which is the history of what people actually paid.
     * Inactive is enough: Plan::forService() only returns active rows, so an
     * inactive plan disappears from checkout while its past stays readable.
     */
    public function retire(): void
    {
        $this->plan->update(['status' => 'inactive']);

        AdminAudit::record('plans.retire', [
            'plan_id' => $this->plan->id,
            'code' => $this->plan->code,
            'live_subscriptions' => $this->liveCount(),
        ]);
    }

    public function restore(): void
    {
        $this->plan->update(['status' => 'active']);

        AdminAudit::record('plans.restore', [
            'plan_id' => $this->plan->id,
            'code' => $this->plan->code,
        ]);
    }

    /**
     * One plan as the console shows it.
     *
     * `liveSubscriptions` is what makes a price change feel consequential
     * rather than abstract, so it travels with the row.
     */
    public static function toRow(Plan $plan, int $liveSubscriptions = 0): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'service' => $plan->service_key->value,
            'monthly' => (float) $plan->price_monthly,
            'yearly' => (float) $plan->price_yearly,
            'currency' => $plan->currency,
            'limits' => [
                'panels' => $plan->max_panels,
                'numbers' => $plan->max_numbers,
                'orders' => $plan->max_orders_monthly,
                'messages' => $plan->max_messages_monthly,
                'refills' => $plan->max_refills_monthly,
            ],
            'status' => $plan->status,
            'sortOrder' => $plan->sort_order,
            'liveSubscriptions' => $liveSubscriptions,
            // A plan can be priced but still not sellable: config/billing.php
            // decides what checkout offers, and a mismatch is worth surfacing
            // rather than leaving someone to wonder why nobody is buying.
            'sellable' => in_array(
                $plan->service_key->value,
                config('billing.sellable', []),
                true,
            ),
        ];
    }

    /**
     * Live subscription counts for a set of plans, in one query.
     *
     * @param  int[]  $planIds
     * @return array<int, int>
     */
    public static function liveCountsFor(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        return Subscription::withoutTenantScope()
            ->whereIn('plan_id', $planIds)
            ->active()
            ->selectRaw('plan_id, count(*) as total')
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** What the checkout would charge for this plan on each term it sells. */
    public static function termPreview(Plan $plan): array
    {
        if ($plan->status !== 'active') {
            return [];
        }

        return collect(Pricing::terms())
            ->map(fn (array $term) => [
                'months' => $term['months'],
                'label' => $term['label'],
                'total' => round(
                    Pricing::serviceTotal($plan->service_key, $term['months']) / 100,
                    2,
                ),
            ])
            ->all();
    }

    private function liveCount(): int
    {
        return Subscription::withoutTenantScope()
            ->where('plan_id', $this->plan->id)
            ->active()
            ->count();
    }
}
