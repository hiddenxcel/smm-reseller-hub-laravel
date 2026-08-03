<?php

namespace App\Services\Payments;

use App\Models\TenantPaymentGateway;

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
     */
    public function make(TenantPaymentGateway $credentials): SnippeClient|NowPaymentsClient|BinancePayClient|CryptomusClient|StripeClient|FlutterwaveClient|PayPalClient|PesapalClient|null
    {
        $apiKey = (string) $credentials->api_key_enc;
        $secret = (string) $credentials->webhook_secret_enc;
        $extra = (string) $credentials->extra_enc;

        return match ($credentials->gateway) {
            'snippe' => new SnippeClient($apiKey, $secret),
            'nowpayments' => new NowPaymentsClient($apiKey, $secret),
            'binance' => new BinancePayClient($apiKey, $secret),
            // Cryptomus and Heleket have no webhook secret of their own, so
            // the second slot holds the merchant UUID instead.
            'cryptomus' => new CryptomusClient($apiKey, $secret),
            'heleket' => new HeleketClient($apiKey, $secret),
            'stripe' => new StripeClient($apiKey, $secret),
            'flutterwave' => new FlutterwaveClient($apiKey, $secret),
            // These two need a third value: PayPal's webhook id, and the id
            // Pesapal issues when the notification URL is registered.
            'paypal' => new PayPalClient($apiKey, $secret, $extra),
            'pesapal' => new PesapalClient($apiKey, $secret, $extra),
            default => null,
        };
    }

    /**
     * The gateway a customer should be sent to.
     *
     * The reseller's chosen default first; anything else active is a fallback,
     * because a default that has since been paused or was never wired up must
     * not leave a paying customer with nowhere to go.
     *
     * Gateways with no client are skipped either way — being marked default
     * cannot make a payment work that has no code behind it.
     */
    public function firstUsableFor(int $tenantId): ?TenantPaymentGateway
    {
        return TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->first(fn (TenantPaymentGateway $row) => Gateway::isReady($row->gateway));
    }
}
