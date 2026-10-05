<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Snippe mobile money, for Tanzania, Kenya and Uganda.
 *
 * Three markets, one account, two different integrations — which is why a
 * market is something the client is built with rather than something it guesses
 * from the amount. Both modes below were verified live against the real API
 * (the published docs differ from the behaviour in places, and the notes here
 * are what the live API actually does):
 *
 *  - TZS: a direct USSD push, `POST /v1/payments`. No page — the prompt goes
 *    straight to the customer's phone and they approve it on the handset.
 *    References come back as "SN…", with the amount wrapped as {currency, value}.
 *
 *  - KES and UGX: only available through the Sessions API, `POST /api/v1/sessions`
 *    (note the /api prefix — without it the path 404s, unlike /v1/payments
 *    which answers on both). The customer is sent to Snippe's own checkout page,
 *    which collects their number and drives the prompt. Snippe REJECTS any
 *    `currency` other than "TZS" on a session, even for a Kenyan or Ugandan
 *    payer: it wants the price in the settlement currency and converts for the
 *    payer itself. So every session is priced in TZS whatever the customer
 *    pays in. References come back as "PAY…", with `amount` a bare number.
 *
 * The shop's own currency is converted to TZS first (see ExchangeRates), so a
 * shop priced in dollars charges the right number of shillings.
 */
class SnippeClient implements PaymentGateway, StatusCheckable, WebhookVerifier
{
    private const PAYMENTS_URL = 'https://api.snippe.sh/v1/payments';

    private const SESSIONS_URL = 'https://api.snippe.sh/api/v1/sessions';

    /** Reject webhooks whose timestamp is further than this from now. */
    private const REPLAY_WINDOW_SECONDS = 300;

    /** What Snippe's API will accept as a price, whichever market the payer is in. */
    public const SETTLEMENT_CURRENCY = 'TZS';

    /** Markets served by the hosted-checkout Sessions API rather than a direct push. */
    private const SESSION_MARKETS = ['KES', 'UGX'];

    public const MARKETS = ['TZS', 'KES', 'UGX'];

    /** @param  string  $market  the payer's market: TZS, KES or UGX */
    public function __construct(
        private string $apiKey,
        private string $webhookSecret,
        private string $market = 'TZS',
    ) {}

    /** The description goes unused: a USSD prompt shows the amount, not a label. */
    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        $converted = ExchangeRates::convert($request->amount, $request->currency, self::SETTLEMENT_CURRENCY);

        if ($converted === null) {
            return PaymentInitiation::failed(
                "No exchange rate for {$request->currency} to ".self::SETTLEMENT_CURRENCY.'. Ask the shop owner to set one.',
            );
        }

        $amount = ExchangeRates::wholeUnits($converted);

        if ($amount < 1) {
            return PaymentInitiation::failed('That amount is too small to charge.');
        }

        return in_array($this->market, self::SESSION_MARKETS, true)
            ? $this->initiateSession($request, $amount)
            : $this->initiateDirectPush($request, $amount);
    }

    private function initiateDirectPush(PaymentRequest $request, int $amount): PaymentInitiation
    {
        // Caught here so the customer is told in the bot's own words, not in
        // Snippe's raw validation error.
        if (trim($request->phone) === '') {
            return PaymentInitiation::failed('A phone number is required for mobile money.');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders(['Idempotency-Key' => $request->reference])
                ->timeout(30)
                ->post(self::PAYMENTS_URL, [
                    'payment_type' => 'mobile',
                    'phone_number' => $request->phone,
                    'details' => [
                        // Snippe takes whole units, not cents.
                        'amount' => $amount,
                        'currency' => self::SETTLEMENT_CURRENCY,
                    ],
                    // Snippe rejects a blank lastname or email outright, so
                    // neither may be sent empty — a one-word name still has to
                    // yield two parts, and a chat customer with no address
                    // still has to have something that parses.
                    'customer' => [
                        'firstname' => $this->firstName($request->customerName),
                        'lastname' => $this->lastName($request->customerName),
                        'email' => $request->emailOrPlaceholder(),
                    ],
                    'webhook_url' => $request->webhookUrl,
                    'metadata' => ['order_id' => $request->reference],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Snippe unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $result = $response->json();

        if (($result['status'] ?? null) === 'success' && isset($result['data']['reference'])) {
            return PaymentInitiation::pushed((string) $result['data']['reference']);
        }

        return PaymentInitiation::failed($result['message'] ?? 'Unknown Snippe error');
    }

    /**
     * Kenya and Uganda: a hosted checkout. The customer opens Snippe's page,
     * which asks for their number and sends the prompt itself.
     */
    private function initiateSession(PaymentRequest $request, int $amount): PaymentInitiation
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders(['Idempotency-Key' => $request->reference])
                ->timeout(30)
                ->post(self::SESSIONS_URL, [
                    'amount' => $amount,
                    // Always TZS — see the class notes. Sending KES or UGX here
                    // is refused outright.
                    'currency' => self::SETTLEMENT_CURRENCY,
                    'allowed_methods' => ['mobile_money'],
                    'customer' => array_filter([
                        'name' => $request->customerName,
                        'phone' => $request->phone,
                        'email' => $request->customerEmail !== '' ? $request->customerEmail : null,
                    ]),
                    'redirect_url' => route('payment.thanks'),
                    'webhook_url' => $request->webhookUrl,
                    'description' => 'Wallet top-up',
                    // Echoed back on the webhook. The payment's own "SN…" id is
                    // not the session's "PAY…" one, so this is how the paid
                    // webhook finds its way home.
                    'metadata' => ['order_id' => $request->reference],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Snippe unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $data = $response->json();

        if ($response->failed() || ! isset($data['data']['reference'], $data['data']['checkout_url'])) {
            return PaymentInitiation::failed($data['message'] ?? 'Unable to start Snippe checkout.');
        }

        return PaymentInitiation::redirect(
            (string) $data['data']['reference'],
            (string) $data['data']['checkout_url'],
        );
    }

    /**
     * The first word of a name, or "Customer" when there is nothing to split —
     * a business_name is often one word, and a bot customer may have none.
     */
    private function firstName(string $name): string
    {
        $first = trim(strtok(trim($name), ' ') ?: '');

        return $first !== '' ? $first : 'Customer';
    }

    /**
     * Whatever follows the first word. "Kuza Panels" gives "Panels"; a
     * one-word name gives a full stop, because Snippe will not take a blank
     * and a real surname is not ours to invent.
     */
    private function lastName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return trim($parts[1] ?? '') !== '' ? trim($parts[1]) : '.';
    }

    /**
     * What Snippe says the payment's state is. "PAY…" is a session, anything
     * else a direct payment — the prefix is what picks the endpoint.
     */
    public function checkStatus(string $reference): ?string
    {
        $url = str_starts_with($reference, 'PAY')
            ? self::SESSIONS_URL.'/'.urlencode($reference)
            : self::PAYMENTS_URL.'/'.urlencode($reference);

        try {
            $response = Http::withToken($this->apiKey)->timeout(15)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        return $response->json('data.status');
    }

    /**
     * HMAC-SHA256 over "{timestamp}.{body}", within a 5-minute window.
     *
     * Snippe sends these as X-Webhook-Signature and X-Webhook-Timestamp. The
     * bare `signature` / `timestamp` names are accepted too, because that is
     * what this client was first written against and what callers that hand it
     * pre-extracted headers still use.
     *
     * @param  array<string, string>  $headers  lower-cased header names
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Log::warning('Rejected a Snippe webhook: no webhook secret is configured');

            return false;
        }

        $headers = array_change_key_case($headers, CASE_LOWER);

        $signature = $headers['x-webhook-signature'] ?? $headers['signature'] ?? '';
        $timestamp = $headers['x-webhook-timestamp'] ?? $headers['timestamp'] ?? '';

        if ($signature === '' || $timestamp === '') {
            return false;
        }

        // Replay protection: an old-but-validly-signed body must not re-credit.
        if (abs(time() - (int) $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Did this webhook report a completed payment?
     *
     * `payment.completed` is the event, `completed` the status; a failed,
     * voided, expired or cancelled payment arrives down the same URL and must
     * not credit anything. Neither word is in the generic success list, which is
     * why Snippe has its own reading rather than borrowing that one.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function isCompleted(array $payload): bool
    {
        $event = (string) ($payload['type'] ?? '');
        $status = strtolower((string) data_get($payload, 'data.status', ''));

        if (in_array($status, ['failed', 'voided', 'expired', 'cancelled'], true) || $event === 'payment.failed') {
            return false;
        }

        return $event === 'payment.completed' || $status === 'completed';
    }
}
