<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Services\Numbers\RentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turn a paid invoice into the things it bought.
 *
 * Called from a gateway webhook, which means two rules dominate:
 *
 *   1. Idempotent. Gateways retry. markSuccess() is a compare-and-swap, so
 *      only the first caller gets past it — every later delivery is a no-op
 *      rather than a second month of subscription.
 *   2. Transactional. A payment that activates the order bot and then fails
 *      on the support bot must activate neither, or the reseller has paid
 *      for something they did not get and we have no record of owing it.
 */
class ActivatePurchase
{
    public function __construct(private RentNumber $rentals) {}

    /**
     * Apply a payment. Returns false when it was already applied, so a
     * retried webhook is a quiet no-op rather than an error.
     */
    public function apply(SubscriptionPayment $payment): bool
    {
        // The compare-and-swap has to happen first and alone: two concurrent
        // webhook deliveries must not both get through to the work below.
        if (! $payment->markSuccess()) {
            return false;
        }

        $tenant = Tenant::find($payment->tenant_id);

        if ($tenant === null) {
            Log::warning('Paid subscription has no tenant', ['payment' => $payment->id]);

            return false;
        }

        DB::transaction(function () use ($payment, $tenant) {
            foreach ($payment->items ?? [] as $item) {
                match ($item['type'] ?? '') {
                    'service' => $this->activateService(
                        $tenant,
                        $item['key'],
                        (int) ($item['months'] ?? $payment->months),
                    ),
                    'number' => $this->claimNumber($tenant, $item),
                    default => Log::warning('Unknown purchase line', [
                        'payment' => $payment->id,
                        'item' => $item,
                    ]),
                };
            }
        });

        return true;
    }

    /**
     * Start or extend one service.
     *
     * Extending adds to whatever is left rather than replacing it — someone
     * who renews early must not lose the days they already paid for. A lapsed
     * subscription restarts from now, since those days are gone.
     */
    private function activateService(Tenant $tenant, string $serviceKey, int $months): void
    {
        $subscription = Subscription::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('service_key', $serviceKey)
            ->lockForUpdate()
            ->first();

        $plan = Plan::forService($serviceKey);

        if ($subscription === null) {
            Subscription::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan?->id,
                'service_key' => $serviceKey,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'ends_at' => now()->addMonths($months),
                'auto_renew' => false,
            ]);

            return;
        }

        $from = $this->extendFrom($subscription);

        $subscription->update([
            'plan_id' => $plan?->id ?? $subscription->plan_id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $subscription->starts_at ?? now(),
            'ends_at' => $from->copy()->addMonths($months),
        ]);
    }

    /**
     * Where the new term starts counting.
     *
     * A sandbox subscription has no paid time to protect, so it starts now
     * even if some ends_at was set during setup.
     */
    private function extendFrom(Subscription $subscription): Carbon
    {
        if ($subscription->status === SubscriptionStatus::Sandbox) {
            return now();
        }

        $endsAt = $subscription->ends_at;

        return $endsAt !== null && $endsAt->isFuture() ? $endsAt : now();
    }

    /**
     * Hand over a rented number.
     *
     * Runs inside the same transaction as the services, so a number that has
     * been taken since checkout began rolls the whole payment application
     * back rather than leaving a half-applied invoice. The reseller has paid,
     * so this must be visible rather than swallowed.
     */
    private function claimNumber(Tenant $tenant, array $item): void
    {
        $this->rentals->claim(
            $tenant,
            (int) $item['key'],
            $item['bot_type'] ?? 'order',
        );
    }
}
