<?php

namespace App\Services\Admin;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Granting and taking away access, by hand.
 *
 * These exist because gateways fail in ways only a person can settle: a payment
 * that landed in a bank account but never reached a webhook, a reseller owed
 * time after an outage, a refund that has to take access back. Every one of
 * them hands out or removes something a reseller paid for, so every one is
 * audited with the dates on both sides of the change.
 *
 * Extending deliberately mirrors ActivatePurchase::activateService(): time is
 * added to whatever is left rather than replacing it, so a manual grant on a
 * live subscription cannot shorten it by accident.
 */
class SubscriptionActions
{
    public function __construct(private Subscription $subscription) {}

    public static function for(Subscription $subscription): self
    {
        return new self($subscription);
    }

    /**
     * Add months, activating the subscription if it was not already.
     *
     * A lapsed or sandbox row restarts from today — those days are gone, and
     * counting from an old ends_at would grant less than it appears to.
     */
    public function extend(int $months, string $reason): void
    {
        $before = $this->subscription->ends_at;

        $from = $before !== null
            && $before->isFuture()
            && $this->subscription->status === SubscriptionStatus::Active
                ? $before->copy()
                : Carbon::now();

        $this->subscription->update([
            'status' => SubscriptionStatus::Active,
            'starts_at' => $this->subscription->starts_at ?? Carbon::now(),
            'ends_at' => $from->addMonths($months),
        ]);

        AdminAudit::onTenant('subscriptions.extend', $this->subscription->tenant_id, [
            'subscription_id' => $this->subscription->id,
            'service' => $this->serviceKey(),
            'months' => $months,
            'before' => $before?->toIso8601String(),
            'after' => $this->subscription->ends_at?->toIso8601String(),
            'reason' => $reason,
        ]);
    }

    /**
     * End access now.
     *
     * ends_at is pulled back to this moment as well as the status changing:
     * the subscription gate reads both, and leaving a future ends_at on a
     * cancelled row would keep the service live for a reseller who has been
     * told it is off.
     */
    public function cancel(string $reason): void
    {
        $before = $this->subscription->ends_at;

        $this->subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => Carbon::now(),
        ]);

        AdminAudit::onTenant('subscriptions.cancel', $this->subscription->tenant_id, [
            'subscription_id' => $this->subscription->id,
            'service' => $this->serviceKey(),
            'had_until' => $before?->toIso8601String(),
            'reason' => $reason,
        ]);
    }

    /**
     * Put a cancelled or expired subscription back, for a given number of
     * months from today.
     *
     * Separate from extend() because the intent is different — this is undoing
     * something, and the audit line should say so.
     */
    public function reinstate(int $months, string $reason): void
    {
        $before = $this->subscription->status->value;

        $this->subscription->update([
            'status' => SubscriptionStatus::Active,
            'starts_at' => $this->subscription->starts_at ?? Carbon::now(),
            'ends_at' => Carbon::now()->addMonths($months),
        ]);

        AdminAudit::onTenant('subscriptions.reinstate', $this->subscription->tenant_id, [
            'subscription_id' => $this->subscription->id,
            'service' => $this->serviceKey(),
            'from_status' => $before,
            'months' => $months,
            'until' => $this->subscription->ends_at?->toIso8601String(),
            'reason' => $reason,
        ]);
    }

    public function setAutoRenew(bool $on): void
    {
        $this->subscription->update(['auto_renew' => $on]);

        AdminAudit::onTenant('subscriptions.auto_renew', $this->subscription->tenant_id, [
            'subscription_id' => $this->subscription->id,
            'service' => $this->serviceKey(),
            'auto_renew' => $on,
        ]);
    }

    private function serviceKey(): string
    {
        $key = $this->subscription->service_key;

        return $key instanceof ServiceKey ? $key->value : (string) $key;
    }
}
