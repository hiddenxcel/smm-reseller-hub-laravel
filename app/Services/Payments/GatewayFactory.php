<?php

namespace App\Services\Payments;

use App\Models\TenantPaymentGateway;
use Illuminate\Support\Collection;

/**
 * Builds a payment client from a reseller's stored credentials.
 *
 * Every client here talks to the reseller's own merchant account — customers
 * pay the reseller, not the platform.
 */
class GatewayFactory
{
    /**
     * Returns null for a gateway that has no client yet, so callers can fall
     * back rather than fataling on a reseller's half-configured choice.
     *
     * The return type is the interface, not a union of every client: adding a
     * gateway below must not mean editing a signature, and callers that need
     * more than initiate() ask with `instanceof` against the smaller
     * interfaces (StatusCheckable, IpnRegistrar, WebhookVerifier).
     */
    public function make(TenantPaymentGateway $credentials): ?PaymentGateway
    {
        $apiKey = (string) $credentials->api_key_enc;
        $secret = (string) $credentials->webhook_secret_enc;
        $extra = (string) $credentials->extra_enc;

        return match ($credentials->gateway) {
            // One Snippe account, three markets. Each is its own gateway, so a
            // customer picks "Kenya" or "Uganda" explicitly and the client
            // never has to guess the market from a number.
            'snippe' => new SnippeClient($apiKey, $secret, 'TZS'),
            'snippe_ke' => new SnippeClient($apiKey, $secret, 'KES'),
            'snippe_ug' => new SnippeClient($apiKey, $secret, 'UGX'),
            // FimiPay likewise: one class, a market per gateway.
            'fimipay_ng', 'fimipay_gh', 'fimipay_cm', 'fimipay_za', 'fimipay_usd'
                => new FimipayClient($apiKey, $secret, $credentials->gateway),
            'nowpayments' => new NowPaymentsClient($apiKey, $secret),
            'binance' => new BinancePayClient($apiKey, $secret),
            // Cryptomus and Heleket have no webhook secret of their own, so
            // the second slot holds the merchant UUID instead.
            'cryptomus' => new CryptomusClient($apiKey, $secret),
            'heleket' => new HeleketClient($apiKey, $secret),
            'stripe' => new StripeClient($apiKey, $secret),
            'flutterwave' => new FlutterwaveClient($apiKey, $secret),
            // Paystack signs webhooks with the secret key itself, so both
            // slots hold the same value — see PaystackClient.
            'paystack' => new PaystackClient($apiKey, $secret),
            // These three need a third value: PayPal's webhook id, the id
            // Pesapal issues when the notification URL is registered, and
            // Razorpay's webhook secret, which is separate from its API keys.
            'paypal' => new PayPalClient($apiKey, $secret, $extra),
            'pesapal' => new PesapalClient($apiKey, $secret, $extra),
            'razorpay' => new RazorpayClient($apiKey, $secret, $extra),
            default => null,
        };
    }

    /**
     * Every gateway a customer of this reseller could pay through, best first.
     *
     * The reseller's chosen default leads, because it is the one they want
     * used; the rest follow as genuine alternatives rather than fallbacks. A
     * reseller who has connected both M-Pesa and a card gateway has done so
     * because their customers want both.
     *
     * Gateways with no client are left out — being connected, or even marked
     * default, cannot make a payment work that has no code behind it.
     *
     * @return Collection<int, TenantPaymentGateway>
     */
    public function usableFor(int $tenantId): Collection
    {
        return TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->filter(fn (TenantPaymentGateway $row) => Gateway::isReady($row->gateway))
            ->values();
    }

    /**
     * The one gateway to use when the customer is not being asked to choose —
     * a reseller with a single gateway, or a caller that already knows which.
     */
    public function firstUsableFor(int $tenantId): ?TenantPaymentGateway
    {
        return $this->usableFor($tenantId)->first();
    }

    /**
     * A specific gateway of this reseller's, if it can take a payment.
     *
     * Goes through usableFor so a paused or unwired gateway cannot be reached
     * by a customer quoting its code back at the bot.
     */
    public function usableGateway(int $tenantId, string $gateway): ?TenantPaymentGateway
    {
        return $this->usableFor($tenantId)
            ->first(fn (TenantPaymentGateway $row) => $row->gateway === $gateway);
    }
}
