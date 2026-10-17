<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Makamesco Pay (makamescopay.com): M-Pesa STK Push in Kenya, charged in KES.
 *
 * A direct push, like Snippe's Tanzania: the prompt goes straight to the
 * customer's phone and they approve it with their M-Pesa PIN. There is no page
 * to send them to. The API is documented at makamescopay.com/docs and is
 * authenticated with the secret key (sk_…) in an X-API-Key header.
 *
 * The money settles into the reseller's own till or paybill, set up in their
 * Makamesco dashboard — which is why one secret key is all we hold.
 *
 * Confirmation is by asking, never by trusting the notification: Makamesco's
 * webhook is registered in the reseller's dashboard and signed with a secret
 * that is shown there, but the status endpoint answers under our own key and
 * needs nothing further to set up. See PaymentWebhookController.
 */
class MakamescoClient implements PaymentGateway, StatusCheckable
{
    private const BASE_URL = 'https://makamescopay.com/api/payments';

    public const CURRENCY = 'KES';

    public function __construct(private string $secretKey) {}

    public function initiate(PaymentRequest $request, string $description = 'Wallet top-up'): PaymentInitiation
    {
        $phone = self::normalisePhone($request->phone);

        if ($phone === null) {
            return PaymentInitiation::failed('A valid Safaricom M-Pesa number is required for this payment method.');
        }

        $converted = ExchangeRates::convert($request->amount, $request->currency, self::CURRENCY);

        if ($converted === null) {
            return PaymentInitiation::failed(
                "No exchange rate for {$request->currency} to ".self::CURRENCY.'. Ask the shop owner to set one.',
            );
        }

        $amount = ExchangeRates::wholeUnits($converted);

        if ($amount < 1) {
            return PaymentInitiation::failed('That amount is too small to charge.');
        }

        try {
            $response = Http::withHeaders(['X-API-Key' => $this->secretKey])
                ->acceptJson()
                ->timeout(30)
                ->post(self::BASE_URL.'/stkpush', [
                    'phoneNumber' => $phone,
                    'amount' => $amount,
                    // Echoed back on the webhook. M-Pesa shortens long
                    // references, so the checkout id below is what is relied on.
                    'accountReference' => $request->reference,
                    'transactionDesc' => $description,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Makamesco unreachable', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach the payment provider');
        }

        $data = $response->json() ?? [];

        if ($response->failed() || ! isset($data['checkoutRequestId']) || ! is_string($data['checkoutRequestId'])) {
            return PaymentInitiation::failed(
                (string) ($data['message'] ?? $data['error'] ?? $data['responseDescription'] ?? 'Unable to send the M-Pesa prompt.'),
            );
        }

        return PaymentInitiation::pushed($data['checkoutRequestId']);
    }

    /** completed | failed | pending, or null when the provider cannot be asked. */
    public function checkStatus(string $reference): ?string
    {
        try {
            $response = Http::withHeaders(['X-API-Key' => $this->secretKey])
                ->acceptJson()
                ->timeout(15)
                ->get(self::BASE_URL.'/status/'.rawurlencode($reference));
        } catch (ConnectionException) {
            return null;
        }

        $status = $response->json('status');

        return $response->successful() && is_string($status) ? self::normaliseStatus($status) : null;
    }

    /**
     * Makamesco's vocabulary in the words this app uses. Anything it does not
     * recognise is pending, the safe way to be wrong: a payment not known to
     * have succeeded must not credit a wallet.
     */
    public static function normaliseStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'completed' => 'completed',
            'failed', 'cancelled', 'canceled' => 'failed',
            default => 'pending',
        };
    }

    /**
     * 254XXXXXXXXX, as M-Pesa wants it: digits only, a leading 0 or + swapped
     * for the country code, and the code added to a bare 7XX / 1XX number.
     */
    public static function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '254')) {
            // already international
        } elseif (str_starts_with($digits, '0')) {
            $digits = '254'.substr($digits, 1);
        } else {
            $digits = '254'.$digits;
        }

        return preg_match('/^254[17]\d{8}$/', $digits) === 1 ? $digits : null;
    }
}
