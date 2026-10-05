<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Services\Billing\ActivatePurchase;
use App\Services\Billing\PlatformGateways;
use App\Services\Payments\BinancePayClient;
use App\Services\Payments\CryptomusClient;
use App\Services\Payments\NowPaymentsClient;
use App\Services\Payments\SnippeClient;
use App\Services\Payments\WebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where the platform's own gateways confirm a subscription payment.
 *
 * Separate from PaymentWebhookController, which handles a reseller's customers
 * topping up their wallets. Same providers, opposite direction: this one runs
 * on the platform's credentials and grants subscriptions, that one runs on the
 * reseller's and credits wallets. Sharing a URL would mean one lookup deciding
 * which of two unrelated tables a payment belongs to, and getting it wrong
 * would credit the wrong account entirely.
 *
 * The same two rules as its sibling:
 *
 *   1. Fail closed. A gateway we hold no webhook secret for cannot be
 *      verified, so its webhooks are refused rather than trusted.
 *   2. Answer 200 to anything we have decided about. Gateways retry non-200s
 *      for hours, and re-delivering a payment already applied achieves
 *      nothing — ActivatePurchase is idempotent, but the load is real.
 */
class BillingWebhookController extends Controller
{
    public function __construct(private ActivatePurchase $activate) {}

    public function __invoke(Request $request, string $gateway): Response
    {
        if (! PlatformGateways::exists($gateway)) {
            return $this->ack('unknown gateway');
        }

        $reference = $this->reference($request);

        if ($reference === null) {
            return $this->ack('no reference');
        }

        $payment = SubscriptionPayment::findByRefAnyTenant($reference);

        // A reference we never issued, or one belonging to another gateway.
        // Acknowledged so it stops being retried, but nothing is granted.
        if ($payment === null || $payment->gateway !== $gateway) {
            Log::warning('Billing webhook for an unknown reference', [
                'gateway' => $gateway,
                'reference' => $reference,
            ]);

            return $this->ack('unknown reference');
        }

        if (! $this->verified($gateway, $request)) {
            // 401, not 200: a signature that does not check out is the one
            // case worth retrying, since a rotated secret can be fixed.
            Log::warning('Billing webhook failed verification', [
                'gateway' => $gateway,
                'payment' => $payment->id,
            ]);

            return response('invalid signature', 401);
        }

        if (! $this->reportsSuccess($gateway, $request)) {
            return $this->ack('not a success event');
        }

        // Idempotent: markSuccess is a compare-and-swap, so a retried delivery
        // returns false here and grants nothing a second time.
        $this->activate->apply($payment);

        return $this->ack('ok');
    }

    /**
     * Our own transaction_ref, wherever this gateway puts it.
     *
     * Only the four platform gateways are handled, so the list is shorter than
     * its sibling's — but the shapes are the same, and a gateway added to
     * config/billing.php needs its key adding here too.
     */
    private function reference(Request $request): ?string
    {
        $payload = $this->flatten($request->all());

        $candidates = [
            'reference',
            // Echoed back from the request's metadata; Snippe's own
            // `data.reference` below is its id, not ours.
            'data.metadata.order_id',
            'metadata.order_id',
            'order_id',
            'orderId',
            'merchant_order_id',
            'data.reference',
            'data.order_id',
            'result.order_id',
            'payment.reference',
            // Binance Pay, once its `data` string has been decoded below.
            'merchantTradeNo',
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
     * Ask the gateway's own client whether this really came from them.
     *
     * A client that cannot verify — no secret configured, or no verifier
     * written — means we cannot trust the request, so it is refused rather
     * than assumed genuine. The old platform returned true when no secret was
     * set, which meant anyone who guessed the URL could activate a
     * subscription for free.
     */
    private function verified(string $gateway, Request $request): bool
    {
        $client = PlatformGateways::make($gateway);

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
     * success may grant a subscription. Unrecognised shapes are treated as
     * not-success, which is the safe way to be wrong.
     */
    private function reportsSuccess(string $gateway, Request $request): bool
    {
        $payload = $this->flatten($request->all());

        // Snippe says "payment.completed" and "completed", neither of which is in
        // the shared lists below, so it is read on its own terms. Without this a
        // paid Snippe subscription was acknowledged and then never activated.
        if ($gateway === 'snippe') {
            return SnippeClient::isCompleted($payload);
        }

        // Binance Pay before anything else. Its envelope carries a top-level
        // `status` of "SUCCESS" meaning only that the notification itself was
        // built successfully — the payment's own outcome is bizStatus, and
        // reading the wrong one would activate a subscription on a closed
        // order.
        $bizStatus = data_get($payload, 'bizStatus');

        if (is_string($bizStatus)) {
            return BinancePayClient::isPaidStatus($bizStatus);
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
        // has paid but the funds have not yet reached us and may still fail.
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
