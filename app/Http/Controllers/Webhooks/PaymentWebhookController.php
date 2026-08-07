<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\CompleteTopup;
use App\Http\Controllers\Controller;
use App\Models\BotPayment;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\PayPalClient;
use App\Services\Payments\StatusCheckable;
use App\Services\Payments\WebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where gateways confirm a customer's payment.
 *
 * One URL per gateway, shared by every reseller — which reseller a payment
 * belongs to comes from our own reference in the payload, not from the URL.
 *
 * Two rules govern everything here:
 *
 *   1. Fail closed. A gateway saved without a webhook secret cannot be
 *      verified, so its webhooks are refused. The old platform allowed them
 *      through, which meant anyone who guessed the URL could credit a wallet.
 *
 *   2. Answer 200 to anything we have decided about. Gateways retry non-200s
 *      for hours, and a retry of a payment we already credited, or one we
 *      cannot identify, achieves nothing but load.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private GatewayFactory $factory,
        private CompleteTopup $completeTopup,
    ) {}

    public function __invoke(Request $request, string $gateway): Response
    {
        if (! Gateway::exists($gateway)) {
            return $this->ack('unknown gateway');
        }

        $reference = $this->reference($request);

        if ($reference === null) {
            return $this->ack('no reference');
        }

        $payment = BotPayment::findByRefAnyTenant($reference);

        // A reference we never issued, or one belonging to another gateway —
        // acknowledged so it stops being retried, but nothing is credited.
        if ($payment === null || $payment->gateway !== $gateway) {
            Log::warning('Payment webhook for an unknown reference', [
                'gateway' => $gateway,
                'reference' => $reference,
            ]);

            return $this->ack('unknown reference');
        }

        $credentials = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $payment->tenant_id)
            ->where('gateway', $gateway)
            ->first();

        if ($credentials === null) {
            return $this->ack('gateway not connected');
        }

        // Some gateways deliberately send no status and no signature — their
        // notification is only a nudge, and the truth is fetched from their
        // API. Those are checked by asking, not by verifying.
        if (Gateway::confirmsByApi($gateway)) {
            if (! $this->confirmedByGateway($credentials, $request)) {
                return $this->ack('not confirmed by the gateway');
            }

            $this->completeTopup->handle($payment);

            return $this->ack('ok');
        }

        if (! $this->verified($credentials, $request)) {
            // 401, not 200: a signature that does not check out is the one case
            // worth retrying, since a rotated secret can be fixed.
            Log::warning('Payment webhook failed verification', [
                'gateway' => $gateway,
                'tenant_id' => $payment->tenant_id,
            ]);

            return response('invalid signature', 401);
        }

        if (! $this->reportsSuccess($request)) {
            return $this->ack('not a success event');
        }

        // CompleteTopup is idempotent: markSuccess is a compare-and-swap, so a
        // retried webhook credits nothing twice.
        $this->completeTopup->handle($payment);

        return $this->ack('ok');
    }

    /**
     * Our own transaction_ref, wherever this gateway puts it.
     *
     * Each names it differently, and some nest it — checking the known spots
     * beats a per-gateway parser for a single string.
     */
    private function reference(Request $request): ?string
    {
        $payload = $request->all();

        $candidates = [
            'reference',
            'order_id',
            'orderId',
            'merchant_order_id',
            'invoice_id',
            'data.reference',
            'data.order_id',
            'result.order_id',
            'payment.reference',
            // Stripe: client_reference_id on the session, metadata as backup.
            'data.object.client_reference_id',
            'data.object.metadata.reference',
            // Flutterwave names it txRef on the webhook, tx_ref on the API.
            'data.tx_ref',
            'txRef',
            'tx_ref',
            // PayPal puts ours in custom_id on the capture resource.
            'resource.custom_id',
            'resource.purchase_units.0.custom_id',
            // Pesapal echoes the id we submitted.
            'OrderMerchantReference',
            // Razorpay nests the entity that carries our reference_id, and
            // which entity that is depends on the event.
            'payload.payment_link.entity.reference_id',
            'payload.payment.entity.notes.reference',
        ];

        foreach ($candidates as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * For gateways whose notification carries no status: go and ask.
     *
     * Pesapal is explicit that its IPN withholds the result "for security
     * reasons", which cuts both ways — anyone can POST to the URL, so the
     * notification proves nothing and only the API's answer counts.
     */
    private function confirmedByGateway(TenantPaymentGateway $credentials, Request $request): bool
    {
        $client = $this->factory->make($credentials);

        if (! $client instanceof StatusCheckable) {
            return false;
        }

        // Pesapal identifies the transaction by its own tracking id, which is
        // the only thing on the notification worth reading.
        $trackingId = (string) (
            $request->input('OrderTrackingId')
            ?? $request->input('orderTrackingId')
            ?? ''
        );

        if ($trackingId === '') {
            return false;
        }

        $status = $client->checkStatus($trackingId);

        return is_string($status) && in_array($status, ['completed', 'success'], true);
    }

    /**
     * Ask the gateway's own client whether this really came from them.
     *
     * A client that cannot verify — no secret stored, or no verifier written —
     * means we cannot trust the request, so it is refused rather than assumed
     * genuine.
     */
    private function verified(TenantPaymentGateway $credentials, Request $request): bool
    {
        $client = $this->factory->make($credentials);

        if (! $client instanceof WebhookVerifier) {
            return false;
        }

        // The raw body, not a re-encoded copy: HMACs are over the exact bytes,
        // and json_encode would reorder keys and change the signature.
        return $client->verifyWebhook($request->getContent(), $this->headers($request));
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    /**
     * Gateways send pending and failed events down the same URL, and only a
     * success may credit a wallet. Unrecognised shapes are treated as
     * not-success, which is the safe way to be wrong.
     */
    private function reportsSuccess(Request $request): bool
    {
        $payload = $request->all();

        // Several gateways say what happened in an event name rather than a
        // status field, so those are matched first — their payloads also carry
        // a `status` that means something else entirely. Razorpay is the clear
        // case: `payload.payment.entity.status` reads "captured" on a refund
        // event too, so only the event name can be trusted.
        $event = data_get($payload, 'type')
            ?? data_get($payload, 'event_type')
            ?? data_get($payload, 'event');

        if (is_string($event)) {
            return in_array($event, [
                // Stripe
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'payment_intent.succeeded',
                // PayPal
                PayPalClient::COMPLETED_EVENT,
                // Paystack
                'charge.success',
                // Razorpay: the link being paid, and the payment behind it.
                'payment_link.paid',
                'payment.captured',
            ], true);
        }

        foreach (['status', 'payment_status', 'data.status', 'result.status'] as $key) {
            $status = data_get($payload, $key);

            if (! is_string($status)) {
                continue;
            }

            return in_array(strtolower($status), [
                'success',
                'succeeded',
                'successful',
                'completed',
                'complete',
                'paid',
                'finished',
                'confirmed',
            ], true);
        }

        return false;
    }

    private function ack(string $reason): Response
    {
        return response($reason, 200);
    }
}
