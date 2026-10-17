<?php

namespace App\Services\Billing;

use App\Models\SubscriptionPayment;
use App\Services\Payments\FimipayClient;
use App\Services\Payments\StatusCheckable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Asks FimiPay whether the subscriptions still waiting were paid.
 *
 * FimiPay's notification is the only thing that used to activate a
 * subscription, and it is not reliable: one did not arrive, and a customer who
 * had paid $20 for a year was left with nothing. So the platform asks too — on
 * a schedule, and when the customer is sent back to us — and activates through
 * exactly the step the webhook uses (ActivatePurchase, safe to run twice).
 *
 * It only ever activates. It never marks a payment failed: a hosted page lets a
 * customer fail once and then pay on the same order.
 *
 * Only FimiPay is asked. Its order id is our own reference, which is all we
 * hold; the other platform gateways need an id they issue, and they push their
 * result reliably.
 */
class ReconcileSubscriptionPayments
{
    /** Past this age an order is no longer asked about (a webhook can still activate it). */
    private const GIVE_UP_AFTER_HOURS = 48;

    private const PER_RUN = 100;

    public function __construct(private ActivatePurchase $activate) {}

    /** @return array{checked: int, activated: int} */
    public function run(): array
    {
        $summary = ['checked' => 0, 'activated' => 0];

        $due = SubscriptionPayment::withoutTenantScope()
            ->where('status', 'pending')
            ->whereIn('gateway', FimipayClient::codes())
            ->where('created_at', '>=', now()->subHours(self::GIVE_UP_AFTER_HOURS))
            ->orderBy('id')
            ->limit(self::PER_RUN)
            ->get()
            ->filter(fn (SubscriptionPayment $payment) => $this->isDue($payment));

        foreach ($due as $payment) {
            $summary['checked']++;

            if ($this->check($payment)) {
                $summary['activated']++;
            }
        }

        return $summary;
    }

    /**
     * Ask about one order, now — for a customer who has just come back from
     * paying. Returns true when it turned out to be paid and was activated.
     */
    public function check(SubscriptionPayment $payment): bool
    {
        if ($payment->status !== 'pending' || ! FimipayClient::isFimipay($payment->gateway)) {
            return false;
        }

        $client = PlatformGateways::make($payment->gateway);

        if (! $client instanceof StatusCheckable) {
            return false;
        }

        try {
            $answer = $client->checkStatus($payment->transaction_ref);
        } catch (\Throwable $e) {
            Log::warning('Could not ask a gateway about a subscription payment', [
                'payment' => $payment->id,
                'gateway' => $payment->gateway,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $answer === 'completed' && $this->activate->apply($payment);
    }

    /**
     * Fresh orders are asked about every run; the older, the less often — the
     * same spacing as wallet top-ups. Kept in the cache so no column is needed.
     */
    private function isDue(SubscriptionPayment $payment): bool
    {
        $ageMinutes = $payment->created_at->diffInMinutes(now());

        $gap = match (true) {
            $ageMinutes < 30 => 1,
            $ageMinutes < 360 => 10,
            default => 60,
        };

        // add() is true only for the first caller within the gap.
        return Cache::add("subpay-check:{$payment->id}", true, now()->addMinutes($gap)->subSeconds(5));
    }
}
