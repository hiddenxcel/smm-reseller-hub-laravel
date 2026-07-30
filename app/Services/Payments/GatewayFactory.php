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
    public function make(TenantPaymentGateway $credentials): SnippeClient|NowPaymentsClient|BinancePayClient|null
    {
        $apiKey = (string) $credentials->api_key_enc;
        $secret = (string) $credentials->webhook_secret_enc;

        return match ($credentials->gateway) {
            'snippe' => new SnippeClient($apiKey, $secret),
            'nowpayments' => new NowPaymentsClient($apiKey, $secret),
            'binance' => new BinancePayClient($apiKey, $secret),
            default => null,
        };
    }

    /**
     * The gateway a customer should be sent to: the reseller's first active
     * one that is actually wired up.
     */
    public function firstUsableFor(int $tenantId): ?TenantPaymentGateway
    {
        return TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->first(fn (TenantPaymentGateway $row) => Gateway::isReady($row->gateway));
    }
}
