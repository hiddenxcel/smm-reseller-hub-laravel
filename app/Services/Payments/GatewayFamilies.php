<?php

namespace App\Services\Payments;

/**
 * Providers that are one merchant account sold in several markets.
 *
 * Each market is a gateway of its own (so checkout, the default gateway and
 * payment reports need no special case), but the keys are the same, so they are
 * entered once for the family and each market is only a switch.
 */
final class GatewayFamilies
{
    /**
     * @var array<string, array{label: string, intro: string, keyLabel: string, secretLabel: string, secretRequired: bool, markets: array<string, string>}>
     */
    public const FAMILIES = [
        'fimipay' => [
            'label' => 'FimiPay',
            'intro' => 'One account, every market. Add your keys once, then switch on the countries you want to sell in.',
            'keyLabel' => 'Secret key (sk_live_… or sk_test_…)',
            'secretLabel' => 'Webhook secret (optional)',
            'secretRequired' => false,
            'markets' => [
                'fimipay_ng' => 'Nigeria — bank transfer (NGN)',
                'fimipay_gh' => 'Ghana — Mobile Money (GHS)',
                'fimipay_cm' => 'Cameroon — Mobile Money (XAF)',
                'fimipay_za' => 'South Africa — card (ZAR)',
                'fimipay_usd' => 'International — card (USD)',
            ],
        ],
        'snippe' => [
            'label' => 'Snippe',
            'intro' => 'One account, every market. Add your keys once, then switch on the countries you want to sell in.',
            'keyLabel' => 'API key',
            'secretLabel' => 'Webhook secret',
            'secretRequired' => true,
            'markets' => [
                'snippe' => 'Tanzania — M-Pesa, Tigo Pesa, Airtel Money (TZS)',
                'snippe_ke' => 'Kenya — M-Pesa & cards (KES)',
                'snippe_ug' => 'Uganda — MTN, Airtel (UGX)',
            ],
        ],
    ];

    public static function exists(string $family): bool
    {
        return isset(self::FAMILIES[$family]);
    }

    /** @return array<int, string> */
    public static function families(): array
    {
        return array_keys(self::FAMILIES);
    }

    /** @return array<int, string> every gateway code in the family */
    public static function codes(string $family): array
    {
        return array_keys(self::FAMILIES[$family]['markets'] ?? []);
    }

    /** The family a gateway code belongs to, if it belongs to one. */
    public static function familyOf(?string $code): ?string
    {
        foreach (self::FAMILIES as $family => $definition) {
            if (isset($definition['markets'][(string) $code])) {
                return $family;
            }
        }

        return null;
    }

    public static function marketLabel(string $code): string
    {
        $family = self::familyOf($code);

        return $family === null ? $code : self::FAMILIES[$family]['markets'][$code];
    }
}
