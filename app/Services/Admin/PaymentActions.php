<?php

namespace App\Services\Admin;

use App\Models\SubscriptionPayment;
use App\Services\Billing\ActivatePurchase;
use Illuminate\Support\Facades\Log;

/**
 * Settling a payment that the automatic path could not.
 *
 * This is the most consequential thing in the console: confirming a payment
 * grants a real subscription for real money, from a screen rather than from a
 * verified gateway signature. Three rules follow from that, and none of them
 * are optional.
 *
 *   1. **It goes through ActivatePurchase, never around it.** That class is
 *      idempotent (markSuccess is a compare-and-swap) and transactional, so a
 *      manual confirmation of a payment whose webhook lands a second later
 *      grants one subscription, not two. Setting status = 'success' by hand
 *      here would skip the activation entirely and leave a reseller who has
 *      paid with nothing switched on.
 *
 *   2. **A reason is required and recorded.** The signature that would normally
 *      justify this is absent by definition, so the admin's stated reason is
 *      the only evidence of why access was granted.
 *
 *   3. **Failing is not the same as refunding.** Marking a payment failed here
 *      records that it never completed; it does not move money back, and it
 *      does not take away a subscription that was already granted. Anything
 *      else would be this screen quietly reversing a transaction the gateway
 *      still believes in.
 */
class PaymentActions
{
    public function __construct(
        private SubscriptionPayment $payment,
        private ActivatePurchase $activate,
    ) {}

    public static function for(SubscriptionPayment $payment, ActivatePurchase $activate): self
    {
        return new self($payment, $activate);
    }

    /**
     * Confirm a payment by hand and grant what it bought.
     *
     * Returns false when it had already been applied — a webhook that arrived
     * while the admin was deciding — so the screen can say "already applied"
     * rather than claiming to have done something it did not.
     */
    public function confirm(string $reason): bool
    {
        $applied = $this->activate->apply($this->payment);

        AdminAudit::onTenant('payments.confirm', $this->payment->tenant_id, [
            'payment_id' => $this->payment->id,
            'reference' => $this->payment->transaction_ref,
            'gateway' => $this->payment->gateway,
            'amount' => (float) $this->payment->amount,
            'currency' => $this->payment->currency,
            'items' => $this->payment->items,
            // False means a webhook beat us to it; the record still belongs in
            // the trail, since an admin did reach for the button.
            'applied' => $applied,
            'reason' => $reason,
        ]);

        if ($applied) {
            Log::info('Subscription payment confirmed by hand', [
                'payment' => $this->payment->id,
                'tenant' => $this->payment->tenant_id,
            ]);
        }

        return $applied;
    }

    /**
     * Record that a payment never completed.
     *
     * Only a pending payment can be failed. A successful one has already
     * granted a subscription, and flipping its status would leave the granted
     * access in place while the record says it was never paid for — the two
     * would disagree, and the subscription is the one that decides what a
     * reseller can actually use.
     */
    public function markFailed(string $reason): bool
    {
        if ($this->payment->status !== 'pending') {
            return false;
        }

        $this->payment->update(['status' => 'failed']);

        AdminAudit::onTenant('payments.fail', $this->payment->tenant_id, [
            'payment_id' => $this->payment->id,
            'reference' => $this->payment->transaction_ref,
            'gateway' => $this->payment->gateway,
            'amount' => (float) $this->payment->amount,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Re-run activation for a payment already marked successful.
     *
     * The narrow case this exists for: the payment was confirmed, but
     * activation partially failed — a rented number was taken between checkout
     * and the webhook, say, rolling the transaction back after markSuccess had
     * already flipped. The row reads 'success' while the reseller has nothing.
     *
     * ActivatePurchase cannot help here, because its compare-and-swap will
     * refuse a payment that is already successful. So the status is stepped
     * back to pending inside the same call that re-applies it — deliberately
     * narrow, deliberately audited, and refused unless the payment really is
     * marked successful.
     */
    public function reapply(string $reason): bool
    {
        if ($this->payment->status !== 'success') {
            return false;
        }

        $this->payment->update(['status' => 'pending']);

        $applied = $this->activate->apply($this->payment);

        if (! $applied) {
            // Should not happen — we just set it to pending — but if it does,
            // the row must not be left pending on a payment that was made.
            $this->payment->update(['status' => 'success']);
        }

        AdminAudit::onTenant('payments.reapply', $this->payment->tenant_id, [
            'payment_id' => $this->payment->id,
            'reference' => $this->payment->transaction_ref,
            'items' => $this->payment->items,
            'applied' => $applied,
            'reason' => $reason,
        ]);

        return $applied;
    }
}
