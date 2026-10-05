<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\CompleteTopup;
use App\Http\Controllers\Controller;
use App\Models\BotPayment;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\BinancePayClient;
use App\Services\Payments\CryptomusClient;
use App\Services\Payments\FimipayClient;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\NowPaymentsClient;
use App\Services\Payments\PayPalClient;
use App\Services\Payments\SnippeClient;
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

        $references = $this->references($request);

        if ($references === []) {
            return $this->ack('no reference');
        }

        // Every id the payload carries, not just the first. A gateway's own id
        // and ours can both be in there, and which is the one we recognise
        // depends on the gateway.
        $payment = null;

        foreach ($references as $reference) {
            $payment = BotPayment::findByRefAnyTenant($reference);

            if ($payment !== null) {
                break;
            }
        }

        // A reference we never issued, or one belonging to another gateway —
        // acknowledged so it stops being retried, but nothing is credited.
        if ($payment === null || $payment->gateway !== $gateway) {
            Log::warning('Payment webhook for an unknown reference', [
                'gateway' => $gateway,
                'references' => $references,
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

        if (! $this->reportsSuccess($gateway, $request)) {
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
    /**
     * Every id in the payload that could be ours, in the order to try them.
     *
     * @return array<int, string>
     */
    private function references(Request $request): array
    {
        $payload = $this->flatten($request->all());

        $candidates = [
            'reference',
            // What we put in the request's metadata, echoed back. Snippe's own
            // `data.reference` is its id, not ours, so this has to be tried too.
            'data.metadata.order_id',
            'metadata.order_id',
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
            // Binance Pay, once its `data` string has been decoded below.
            'merchantTradeNo',
        ];

        $found = [];

        foreach ($candidates as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && $value !== '') {
                $found[] = $value;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Binance Pay's payload nests the part that matters as a JSON *string*
     * under `data`, so merchantTradeNo and bizStatus are invisible to
     * data_get() until it is decoded.
     *
     * Merged into the top level rather than parsed separately, so the
     * reference and status lookups keep working the way they do for every
     * other gateway.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function flatten(array $payload): array
    {
        $data = $payload['data'] ?? null;

        if (! is_string($data) || $data === '') {
            return $payload;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? [...$payload, ...$decoded] : $payload;
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
    private function reportsSuccess(string $gateway, Request $request): bool
    {
        $payload = $this->flatten($request->all());

        // Gateways with a reading of their own, before the generic one: their
        // success words and event names are not in the shared lists, and
        // adding them there would change what every other gateway credits.
        if (str_starts_with($gateway, 'snippe')) {
            return SnippeClient::isCompleted($payload);
        }

        if (FimipayClient::isFimipay($gateway)) {
            return FimipayClient::isCompleted($payload);
        }

        // Binance Pay before anything else. Its envelope carries a top-level
        // `status` of "SUCCESS" meaning only that the notification itself was
        // built successfully — the payment's own outcome is bizStatus, and
        // reading the wrong one would credit a wallet on a closed order.
        $bizStatus = data_get($payload, 'bizStatus');

        if (is_string($bizStatus)) {
            return BinancePayClient::isPaidStatus($bizStatus);
        }

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

        $status = null;

        foreach (['status', 'payment_status', 'data.status', 'result.status'] as $key) {
            $found = data_get($payload, $key);

            if (is_string($found)) {
                $status = $found;

                break;
            }
        }

        if ($status === null) {
            return false;
        }

        // Each gateway's own vocabulary, because the same word means different
        // things to different providers. A shared word-list once decided this,
        // and it credited NOWPayments' `confirmed` — a state where the customer
        // has paid but the funds have not yet reached the reseller and may
        // still fail — and Cryptomus's `wrong_amount`, where they underpaid.
        return match ($gateway) {
            'nowpayments' => NowPaymentsClient::isPaidStatus($status),
            'cryptomus', 'heleket' => CryptomusClient::isPaidStatus($status),
            default => in_array(strtolower($status), [
                'success',
                'succeeded',
                'successful',
                'completed',
                'complete',
                'paid',
            ], true),
        };
    }

    private function ack(string $reason): Response
    {
        return response($reason, 200);
    }
}
