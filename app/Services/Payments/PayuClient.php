<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * PayU (India) hosted checkout — UPI, cards, net banking and wallets, charged
 * in rupees.
 *
 * PayU wants the order POSTed to its payment page as a form, but a WhatsApp
 * customer can only be handed a link. So the link points at a page of ours
 * (PaymentFormController) that holds the order and submits it to PayU: the
 * order fields and their hash travel in the link, encrypted and signed, and
 * the merchant salt never does — only the hash computed from it.
 *
 * Two credentials: the merchant key and the salt. The salt signs the request
 * and verifies PayU's response, so it is also what makes a notification
 * believable. A payment is credited only on a response whose hash checks out
 * and whose status is `success`, or when PayU itself is asked and says so.
 *
 * An optional third value of "test" points everything at PayU's sandbox.
 */
class PayuClient implements PaymentGateway, StatusCheckable, WebhookVerifier
{
    private const LIVE_PAYMENT_URL = 'https://secure.payu.in/_payment';

    private const TEST_PAYMENT_URL = 'https://test.payu.in/_payment';

    private const LIVE_VERIFY_URL = 'https://info.payu.in/merchant/postservice.php?form=2';

    private const TEST_VERIFY_URL = 'https://test.payu.in/merchant/postservice.php?form=2';

    /** The form page will only ever submit to one of these. */
    public const PAYMENT_URLS = [self::LIVE_PAYMENT_URL, self::TEST_PAYMENT_URL];

    public function __construct(
        private string $key,
        private string $salt,
        private string $mode = '',
    ) {}

    private function isTest(): bool
    {
        return strtolower(trim($this->mode)) === 'test';
    }

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        if ($this->key === '' || $this->salt === '') {
            return PaymentInitiation::failed('PayU is not set up: the merchant key and salt are required.');
        }

        $converted = ExchangeRates::convert($request->amount, $request->currency, 'INR');

        if ($converted === null) {
            return PaymentInitiation::failed(
                "No exchange rate for {$request->currency} to INR. Ask the shop owner to set one.",
            );
        }

        $amount = number_format(round((float) $converted, 2), 2, '.', '');

        if ((float) $amount < 1) {
            return PaymentInitiation::failed('That amount is too small to charge.');
        }

        // PayU allows 25 letters, digits and underscores; our own reference is
        // longer than that, so PayU gets its own, remembered as the payment's
        // gateway reference.
        $txnid = 'tu'.Str::lower(Str::random(20));
        $phone = preg_replace('/\D+/', '', $request->phone) ?? '';

        $fields = [
            'key' => $this->key,
            'txnid' => $txnid,
            'amount' => $amount,
            'productinfo' => mb_substr($description, 0, 100),
            'firstname' => $this->firstName($request->customerName),
            'email' => $this->email($request, $txnid),
            'phone' => substr($phone, -10),
            'surl' => route('webhooks.payment.return', 'payu'),
            'furl' => route('webhooks.payment.return', 'payu'),
        ];

        $fields['hash'] = $this->requestHash($fields);

        $link = URL::temporarySignedRoute('payment.form', now()->addHours(3), [
            'd' => Crypt::encryptString(json_encode([
                'action' => $this->isTest() ? self::TEST_PAYMENT_URL : self::LIVE_PAYMENT_URL,
                'fields' => $fields,
            ], JSON_THROW_ON_ERROR)),
        ]);

        return PaymentInitiation::redirect($txnid, $link);
    }

    /**
     * sha512(key|txnid|amount|productinfo|firstname|email|udf1..udf5||||||salt)
     *
     * No udf fields are used, so those five are empty.
     *
     * @param  array<string, string>  $fields
     */
    public function requestHash(array $fields): string
    {
        return strtolower(hash('sha512', implode('|', [
            $fields['key'], $fields['txnid'], $fields['amount'], $fields['productinfo'],
            $fields['firstname'], $fields['email'],
            '', '', '', '', '', '', '', '', '', '', $this->salt,
        ])));
    }

    /**
     * PayU's response hash, over the fields in reverse:
     * sha512([additionalCharges|]salt|status||||||udf5..udf1|email|firstname|productinfo|amount|txnid|key)
     *
     * Values are used exactly as PayU sent them. Refused when no salt is
     * saved, and when the response is for a different merchant key.
     *
     * @param  array<string, mixed>  $params
     */
    public function verifyParams(array $params): bool
    {
        if ($this->salt === '' || $this->key === '') {
            Log::warning('Rejected a PayU response: the merchant key or salt is not configured');

            return false;
        }

        foreach (['status', 'email', 'firstname', 'productinfo', 'amount', 'txnid', 'key', 'hash'] as $field) {
            if (! isset($params[$field]) || ! is_scalar($params[$field])) {
                return false;
            }
        }

        if ((string) $params['key'] !== $this->key) {
            return false;
        }

        $parts = [
            $this->salt, $params['status'], '', '', '', '', '', '', '', '', '', '',
            $params['email'], $params['firstname'], $params['productinfo'],
            $params['amount'], $params['txnid'], $params['key'],
        ];

        if (filled($params['additionalCharges'] ?? null)) {
            array_unshift($parts, $params['additionalCharges']);
        }

        return hash_equals(strtolower(hash('sha512', implode('|', $parts))), strtolower((string) $params['hash']));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        parse_str($body, $params);

        return $this->verifyParams($params);
    }

    /** Only a response that says `success` may credit a wallet. */
    public static function isPaid(array $params): bool
    {
        return strtolower((string) ($params['status'] ?? '')) === 'success';
    }

    /**
     * Ask PayU what became of a transaction (verify_payment).
     *
     * @return string|null completed | failed | pending, or null when PayU could not be reached
     */
    public function checkStatus(string $reference): ?string
    {
        $command = 'verify_payment';

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->post($this->isTest() ? self::TEST_VERIFY_URL : self::LIVE_VERIFY_URL, [
                    'key' => $this->key,
                    'command' => $command,
                    'var1' => $reference,
                    'hash' => strtolower(hash('sha512', implode('|', [$this->key, $command, $reference, $this->salt]))),
                ]);
        } catch (ConnectionException) {
            return null;
        }

        $status = $response->json("transaction_details.{$reference}.status");

        if (! is_string($status)) {
            return $response->successful() ? 'pending' : null;
        }

        return match (strtolower($status)) {
            'success' => 'completed',
            'failure', 'failed', 'usercancelled', 'dropped', 'bounced' => 'failed',
            default => 'pending',
        };
    }

    /** PayU wants a first name; use the first word of whatever the customer is called, in plain letters. */
    private function firstName(string $name): string
    {
        $first = Str::of(Str::ascii(trim($name)))->before(' ')->replaceMatches('/[^A-Za-z]/', '')->toString();

        return $first !== '' ? mb_substr($first, 0, 30) : 'Customer';
    }

    /** PayU requires an email and a chat customer has none; one that parses, unique to the payment. */
    private function email(PaymentRequest $request, string $txnid): string
    {
        if ($request->customerEmail !== '') {
            return $request->customerEmail;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com';

        return "customer+{$txnid}@{$host}";
    }
}
