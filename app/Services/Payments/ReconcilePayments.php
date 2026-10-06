<?php

namespace App\Services\Payments;

use App\Actions\Payments\CompleteTopup;
use App\Models\BotPayment;
use App\Models\TenantPaymentGateway;
use Illuminate\Support\Facades\Log;

/**
 * Asks each gateway whether the payments still waiting have been paid.
 *
 * A webhook is the quick way to learn a payment cleared, and it is not
 * reliable: a gateway can fail to send it, we can be down when it does, a
 * customer can close the page before being sent back. Anyone who has paid
 * must not be left out of pocket for that, so this asks directly, and credits
 * through exactly the same step a webhook would (CompleteTopup, which is safe
 * to run more than once).
 *
 * It only ever credits. It never marks a payment failed: a hosted page lets a
 * customer fail once and then pay on the same payment, and "failed" is not
 * final. Money that arrives late is credited late; nothing is given up on.
 */
class ReconcilePayments
{
    /** Past this age a payment is no longer asked about (a webhook can still credit it). */
    private const GIVE_UP_AFTER_HOURS = 48;

    private const PER_RUN = 200;

    /** Gateways whose status cannot be looked up from what we hold. */
    private const SKIP = ['pesapal'];

    public function __construct(
        private GatewayFactory $factory,
        private CompleteTopup $complete,
    ) {}

    /**
     * @return array{checked: int, credited: int}
     */
    public function run(?int $tenantId = null): array
    {
        $summary = ['checked' => 0, 'credited' => 0];

        $due = BotPayment::withoutTenantScope()
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subHours(self::GIVE_UP_AFTER_HOURS))
            ->whereNotIn('gateway', self::SKIP)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id')
            ->limit(self::PER_RUN * 3)
            ->get()
            ->filter(fn (BotPayment $payment) => $this->isDue($payment))
            ->take(self::PER_RUN);

        foreach ($due as $payment) {
            $summary['checked']++;

            if ($this->check($payment)) {
                $summary['credited']++;
            }
        }

        return $summary;
    }

    /**
     * Fresh payments are asked about every run; the older, the less often —
     * a customer standing at the phone wants an answer in a minute or two, and
     * one that is a day old is not going to be settled by asking more often.
     */
    private function isDue(BotPayment $payment): bool
    {
        if ($payment->last_checked_at === null) {
            return true;
        }

        $ageMinutes = $payment->created_at->diffInMinutes(now());

        $gap = match (true) {
            $ageMinutes < 30 => 1,
            $ageMinutes < 360 => 10,
            default => 60,
        };

        return $payment->last_checked_at->diffInMinutes(now()) >= $gap;
    }

    /** Whether this payment turned out to be paid, and was credited. */
    private function check(BotPayment $payment): bool
    {
        $credentials = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $payment->tenant_id)
            ->where('gateway', $payment->gateway)
            ->first();

        $client = $credentials ? $this->factory->make($credentials) : null;

        if (! $client instanceof StatusCheckable) {
            return false;
        }

        $payment->forceFill(['last_checked_at' => now()])->save();

        try {
            $answer = $client->checkStatus(
                PaymentOutcome::referenceFor($payment->gateway, $payment->transaction_ref, $payment->gateway_reference),
            );
        } catch (\Throwable $e) {
            Log::warning('Could not ask a gateway about a payment', [
                'payment' => $payment->id,
                'gateway' => $payment->gateway,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! PaymentOutcome::isPaid($payment->gateway, $answer)) {
            return false;
        }

        $this->complete->handle($payment);

        return $payment->fresh()->status === 'success';
    }
}