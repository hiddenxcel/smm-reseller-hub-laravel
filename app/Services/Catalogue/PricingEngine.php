<?php

namespace App\Services\Catalogue;

use App\Models\BotService;
use App\Models\PricingRule;
use App\Models\ServicePriceHistory;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning a panel's cost into the reseller's price.
 *
 * All arithmetic here is bcmath on strings. Prices are per 1,000 units and the
 * columns hold four decimal places, so a service can legitimately cost
 * 0.0009 — at that scale binary floats round in ways that show up as a margin
 * quietly going negative, and this is the reseller's income.
 *
 * Two things use this: the bulk pricing tool, where the reseller sweeps a
 * selection by hand, and the sync, where standing rules re-apply themselves
 * after a panel moves its costs.
 */
class PricingEngine
{
    /** Scale for intermediate arithmetic. Results are stored at 4. */
    private const SCALE = 6;

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * The tenant's rules, in the order they are tried.
     *
     * @return Collection<int, PricingRule>
     */
    public function rules(): Collection
    {
        return PricingRule::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The price a rule would set for this service, or null when no rule covers
     * it or the panel never reported a cost.
     *
     * First match wins. Rules do not stack: two markups applied in sequence is
     * never what someone means by "Instagram +30%, everything +10%", and the
     * result would depend on an ordering nobody can see on screen.
     *
     * @param  Collection<int, PricingRule>|null  $rules  pass in to avoid re-querying per service
     */
    public function priceFromRules(BotService $service, ?Collection $rules = null): ?string
    {
        if ($service->cost_price === null) {
            return null;
        }

        $rule = ($rules ?? $this->rules())->first(fn (PricingRule $rule) => $rule->matches($service));

        if ($rule === null) {
            return null;
        }

        return $this->applyRule($rule, (string) $service->cost_price);
    }

    /** What one rule makes of one cost. Public so the rules editor can preview. */
    public function applyRule(PricingRule $rule, string $cost): string
    {
        $amount = (string) $rule->amount;

        $price = match ($rule->mode) {
            PricingRule::PERCENT => bcadd(
                $cost,
                bcdiv(bcmul($cost, $amount, self::SCALE), '100', self::SCALE),
                self::SCALE,
            ),
            PricingRule::FIXED => bcadd($cost, $amount, self::SCALE),
            PricingRule::MULTIPLIER => bcmul($cost, $amount, self::SCALE),
            default => $cost,
        };

        // Guard rails come after the markup, because their whole job is to
        // correct what a percentage does at the extremes: +30% on a service
        // costing 0.02 is six thousandths of a cent of margin.
        if ($rule->min_profit !== null) {
            $floor = bcadd($cost, (string) $rule->min_profit, self::SCALE);

            if (bccomp($price, $floor, self::SCALE) === -1) {
                $price = $floor;
            }
        }

        if ($rule->max_profit !== null) {
            $ceiling = bcadd($cost, (string) $rule->max_profit, self::SCALE);

            if (bccomp($price, $ceiling, self::SCALE) === 1) {
                $price = $ceiling;
            }
        }

        if ($rule->round_to !== null && bccomp((string) $rule->round_to, '0', 4) === 1) {
            $price = $this->roundUpTo($price, (string) $rule->round_to);
        }

        return $this->normalise($price);
    }

    /**
     * Work out what a bulk adjustment would do, without saving anything.
     *
     * The preview and the apply run through the same method so the numbers a
     * reseller approves are the numbers that get written — a separate preview
     * path is exactly how the two drift apart.
     *
     * @param  Collection<int, BotService>  $services
     * @return list<array{id: int, name: string, from: string, to: string, cost: ?string, profit: ?string, underwater: bool}>
     */
    public function previewBulk(Collection $services, BulkAdjustment $adjustment): array
    {
        return $services
            ->map(function (BotService $service) use ($adjustment) {
                $from = (string) $service->my_price;
                $to = $adjustment->applyTo($from, $service->cost_price === null ? null : (string) $service->cost_price);

                $profit = $service->cost_price === null
                    ? null
                    : bcsub($to, (string) $service->cost_price, 4);

                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'from' => $from,
                    'to' => $to,
                    'cost' => $service->cost_price === null ? null : (string) $service->cost_price,
                    'profit' => $profit,
                    'underwater' => $profit !== null && bccomp($profit, '0', 4) === -1,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Apply a bulk adjustment for real.
     *
     * One transaction, and each change is recorded — a sweep across 400
     * services that cannot be explained afterwards is worse than no sweep.
     *
     * @param  Collection<int, BotService>  $services
     * @return array{changed: int, skipped: int}
     */
    public function applyBulk(Collection $services, BulkAdjustment $adjustment, string $note): array
    {
        $changed = 0;
        $skipped = 0;

        DB::transaction(function () use ($services, $adjustment, $note, &$changed, &$skipped) {
            foreach ($services as $service) {
                $from = (string) $service->my_price;
                $to = $adjustment->applyTo(
                    $from,
                    $service->cost_price === null ? null : (string) $service->cost_price,
                );

                if (bccomp($from, $to, 4) === 0) {
                    $skipped++;

                    continue;
                }

                $this->setPrice($service, $to, ServicePriceHistory::BULK, $note);
                $changed++;
            }
        });

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * Write a new price and record why.
     *
     * Every price change in the application goes through here. That is the
     * point: the history is only trustworthy if there is no second path that
     * writes my_price without leaving a row.
     */
    public function setPrice(
        BotService $service,
        string $price,
        string $reason,
        ?string $note = null,
        ?string $newCost = null,
    ): void {
        $oldPrice = (string) $service->my_price;
        $oldCost = $service->cost_price === null ? null : (string) $service->cost_price;

        $service->forceFill(array_filter([
            'my_price' => $this->normalise($price),
            'cost_price' => $newCost,
        ], fn ($value) => $value !== null))->save();

        ServicePriceHistory::withoutTenantScope()->create([
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'reason' => $reason,
            'old_price' => $oldPrice,
            'new_price' => $this->normalise($price),
            'old_cost' => $oldCost,
            'new_cost' => $newCost ?? $oldCost,
            'note' => $note,
        ]);
    }

    /**
     * Round up to the nearest step. Up rather than nearest: rounding down
     * takes money off the reseller, and a rule meant to protect margin should
     * never be the thing that shaves it.
     */
    private function roundUpTo(string $value, string $step): string
    {
        if (bccomp($step, '0', self::SCALE) !== 1) {
            return $value;
        }

        $steps = bcdiv($value, $step, 0);
        $exact = bcmul($steps, $step, self::SCALE);

        if (bccomp($exact, $value, self::SCALE) === -1) {
            $steps = bcadd($steps, '1', 0);
        }

        return bcmul($steps, $step, self::SCALE);
    }

    /** Clamp to the column's four places, and never below zero. */
    private function normalise(string $value): string
    {
        if (bccomp($value, '0', self::SCALE) === -1) {
            return '0.0000';
        }

        return bcadd($value, '0', 4);
    }
}
