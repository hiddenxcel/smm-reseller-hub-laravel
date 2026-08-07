<?php

namespace App\Services\Admin;

use App\Enums\ServiceKey;
use App\Models\ActivityLog;
use App\Models\BotCustomer;
use App\Models\BotOrder;
use App\Models\NumberRental;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\TenantPaymentGateway;
use App\Models\TenantWhatsApp;
use Illuminate\Support\Carbon;

/**
 * One reseller, in as much detail as the console shows.
 *
 * Every read crosses the tenant scope explicitly. That is not defensive habit:
 * the admin viewing this page has no tenant session of their own, so the scope
 * would be a no-op and return every reseller's rows under this one's name. The
 * `where('tenant_id')` on each query is what makes the page correct, not what
 * makes it safe.
 *
 * Nothing here exposes a credential. Panels and gateways report whether a key
 * is present, never the key — an admin needs to know a reseller is connected,
 * not to be able to spend their balance.
 */
class TenantProfile
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function overview(): array
    {
        return [
            'id' => $this->tenant->id,
            'name' => $this->tenant->business_name,
            'email' => $this->tenant->email,
            'phone' => $this->tenant->phone,
            'status' => $this->tenant->status,
            'lang' => $this->tenant->lang,
            'referralCode' => $this->tenant->referral_code,
            'referralCredit' => (float) $this->tenant->referral_credit,
            'hasPaid' => (bool) $this->tenant->first_payment_done,
            'referredBy' => $this->referrer(),
            'joinedAt' => $this->tenant->created_at?->toIso8601String(),
            'stats' => $this->stats(),
            'services' => $this->serviceStates(),
        ];
    }

    /** Lifetime figures, and what the reseller is holding for their customers. */
    public function stats(): array
    {
        return [
            'revenue' => round((float) SubscriptionPayment::withoutTenantScope()
                ->where('tenant_id', $this->tenant->id)
                ->where('status', 'success')
                ->sum('amount'), 2),
            'orders' => BotOrder::withoutTenantScope()
                ->where('tenant_id', $this->tenant->id)
                ->count(),
            'customers' => BotCustomer::withoutTenantScope()
                ->where('tenant_id', $this->tenant->id)
                ->count(),
            // Customer money the reseller holds. It is a liability, not income,
            // and it is what a suspension would strand.
            'walletsHeld' => round((float) BotCustomer::withoutTenantScope()
                ->where('tenant_id', $this->tenant->id)
                ->sum('balance'), 2),
            'ordersLast30' => BotOrder::withoutTenantScope()
                ->where('tenant_id', $this->tenant->id)
                ->where('created_at', '>=', Carbon::today()->subDays(29))
                ->count(),
        ];
    }

    /** serviceKey => 'active' | 'sandbox' | 'locked', as the tenant sees it. */
    public function serviceStates(): array
    {
        return Subscription::stateMap($this->tenant->id);
    }

    public function subscriptions(): array
    {
        return Subscription::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->with('plan')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'service' => $subscription->service_key instanceof ServiceKey
                    ? $subscription->service_key->value
                    : $subscription->service_key,
                'plan' => $subscription->plan?->name,
                'status' => $subscription->status->value,
                'startsAt' => $subscription->starts_at?->toIso8601String(),
                'endsAt' => $subscription->ends_at?->toIso8601String(),
                'autoRenew' => (bool) $subscription->auto_renew,
                'expired' => $subscription->ends_at !== null
                    && $subscription->ends_at->isPast(),
            ])
            ->all();
    }

    public function payments(int $limit = 50): array
    {
        return SubscriptionPayment::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (SubscriptionPayment $payment) => [
                'id' => $payment->id,
                'gateway' => $payment->gateway,
                'reference' => $payment->transaction_ref,
                'amount' => (float) $payment->amount,
                'creditApplied' => (float) $payment->credit_applied,
                'currency' => $payment->currency,
                'months' => $payment->months,
                'items' => $payment->items,
                'status' => $payment->status,
                'at' => $payment->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function orders(int $limit = 50): array
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BotOrder $order) => [
                'id' => $order->id,
                'service' => $order->service_name,
                'customer' => $order->customer_phone,
                'quantity' => $order->quantity,
                'amount' => $order->amount !== null ? (float) $order->amount : null,
                'status' => $order->status,
                'paymentStatus' => $order->payment_status,
                'at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function customers(int $limit = 50): array
    {
        return BotCustomer::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BotCustomer $customer) => [
                'id' => $customer->id,
                'phone' => $customer->phone,
                'name' => $customer->name,
                'balance' => (float) $customer->balance,
                'totalSpent' => (float) $customer->total_spent,
                'blocked' => $customer->blocked_at !== null,
                'lastSeenAt' => $customer->last_seen_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Panels and payment gateways — connected or not, never the credentials. */
    public function panels(): array
    {
        $panels = TenantPanel::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->get()
            ->map(fn (TenantPanel $panel) => [
                'id' => $panel->id,
                'name' => $panel->name,
                'type' => $panel->panel_type,
                'url' => $panel->api_url,
                'balance' => $panel->last_balance !== null ? (float) $panel->last_balance : null,
                'currency' => $panel->balance_currency,
                'services' => $panel->services_count,
                'checkedAt' => $panel->last_checked_at?->toIso8601String(),
                'status' => $panel->status,
            ])
            ->all();

        $gateways = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->get()
            ->map(fn (TenantPaymentGateway $gateway) => [
                'id' => $gateway->id,
                'gateway' => $gateway->gateway,
                'status' => $gateway->status,
                'isDefault' => (bool) $gateway->is_default,
            ])
            ->all();

        return ['panels' => $panels, 'gateways' => $gateways];
    }

    public function numbers(): array
    {
        $numbers = TenantWhatsApp::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->get()
            ->map(fn (TenantWhatsApp $number) => [
                'id' => $number->id,
                'display' => $number->display_number ?? $number->phone_number_id,
                'source' => $number->source,
                'bot' => $number->bot_type,
                'status' => $number->status,
            ])
            ->all();

        $rentals = NumberRental::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->with('platformNumber')
            ->orderByDesc('id')
            ->get()
            ->map(fn (NumberRental $rental) => [
                'id' => $rental->id,
                'number' => $rental->platformNumber?->display_number,
                'country' => $rental->platformNumber?->country,
                'cost' => $rental->platformNumber !== null
                    ? (float) $rental->platformNumber->monthly_cost
                    : null,
                'currency' => $rental->platformNumber?->currency,
                'startsAt' => $rental->starts_at?->toIso8601String(),
                'endsAt' => $rental->ends_at?->toIso8601String(),
                'status' => $rental->status,
            ])
            ->all();

        return ['numbers' => $numbers, 'rentals' => $rentals];
    }

    /**
     * What has been done to this account, by anyone.
     *
     * Both the reseller's own actions and every admin action taken against
     * them: the reseller-facing question ("why did my account change?") is
     * answered by the two read together, not by either alone.
     */
    public function activity(int $limit = 100): array
    {
        return ActivityLog::query()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('actor_type', 'tenant')
                        ->where('actor_id', $this->tenant->id);
                })->orWhere(function ($q) {
                    // Admin actions carry the target in details, since the
                    // actor is the admin, not the reseller.
                    $q->where('actor_type', 'superadmin')
                        ->where('details->tenant_id', $this->tenant->id);
                });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $entry) => [
                'id' => $entry->id,
                'actorType' => $entry->actor_type,
                'actor' => $entry->details['actor'] ?? null,
                'action' => $entry->action,
                'details' => $entry->details,
                'ip' => $entry->ip,
                'at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function referrer(): ?array
    {
        $referrer = $this->tenant->referredBy;

        return $referrer === null ? null : [
            'id' => $referrer->id,
            'name' => $referrer->business_name,
        ];
    }
}
