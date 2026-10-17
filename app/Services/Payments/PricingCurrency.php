<?php

namespace App\Services\Payments;

use App\Models\Tenant;

/**
 * The single place that decides what currency a tenant's own prices,
 * balances and orders are in.
 *
 * Deliberately not the same thing as the bot's `shop.currency` setting
 * (App\Services\Bots\BotSettings) — that only ever labelled an amount for a
 * WhatsApp message. This is the real currency new catalogue prices and wallet
 * writes are stored in from the moment a tenant sets it.
 *
 * `tenants.pricing_currency` being null is "USD, unchanged" — every tenant
 * that has never touched this defaults here rather than at each call site, so
 * "untouched tenants see zero behaviour change" only has to be true once.
 */
class PricingCurrency
{
    public const DEFAULT = 'USD';

    public static function for(Tenant $tenant): string
    {
        return $tenant->pricing_currency ?? self::DEFAULT;
    }

    /** Every currency a tenant can choose, for the switcher. */
    public static function choices(): array
    {
        return ExchangeRates::catalogue();
    }

    /**
     * A cost the panel reported, expressed in the shop's currency.
     *
     * A panel prices in its own currency — a Kenyan one in shillings, most in
     * dollars — and the shop's prices are in the shop's. Subtracting one from
     * the other is only meaningful once they are the same, so the cost is
     * brought across here, once, when it is read. Same currency, or a panel
     * that does not say, passes through untouched; a pair with no rate yields
     * null rather than a guess.
     */
    public static function costFromPanel(string $cost, ?string $panelCurrency, Tenant $tenant): ?string
    {
        // A shop that has never chosen a currency keeps what it always had:
        // the panel's number as it came. Converting for it would turn a
        // shilling panel's costs into dollars under prices that are still
        // in shillings — the very mix-up choosing a currency exists to fix.
        if ($tenant->pricing_currency === null) {
            return $cost;
        }

        $shop = self::for($tenant);
        $panel = strtoupper(trim((string) $panelCurrency));

        if ($panel === '' || $panel === $shop) {
            return $cost;
        }

        return ExchangeRates::convert($cost, $panel, $shop);
    }

    public static function isValid(string $currency): bool
    {
        return ExchangeRates::supports(strtoupper(trim($currency)));
    }
}
