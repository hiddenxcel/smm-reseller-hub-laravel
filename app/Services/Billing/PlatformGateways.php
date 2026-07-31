<?php

namespace App\Services\Billing;

use App\Services\Payments\CryptomusClient;
use App\Services\Payments\HeleketClient;
use App\Services\Payments\NowPaymentsClient;
use App\Services\Payments\SnippeClient;
use Illuminate\Support\Arr;

/**
 * The platform's own merchant accounts — the ones a reseller pays US through.
 *
 * Deliberately separate from GatewayFactory, which builds clients from a
 * reseller's stored credentials for taking money from THEIR customers. Same
 * providers, opposite direction, different keys; conflating them would have
 * a reseller's subscription paid into their own account.
 */
class PlatformGateways
{
    /**
     * Gateways a reseller can actually pay with right now: listed in
     * config/billing.php AND holding real credentials.
     *
     * A gateway with no keys is left out rather than offered and then failing
     * on the next screen.
     *
     * @return array<int, array{code: string, label: string, type: string}>
     */
    public static function available(): array
    {
        return collect(config('billing.gateways', []))
            ->filter(fn (array $gateway, string $code) => self::isConfigured($code))
            ->map(fn (array $gateway, string $code) => [
                'code' => $code,
                'label' => $gateway['label'],
                'type' => $gateway['type'],
            ])
            ->values()
            ->all();
    }

    public static function isConfigured(string $code): bool
    {
        $keys = config("services.billing.{$code}", []);

        return match ($code) {
            'snippe' => filled(Arr::get($keys, 'api_key')),
            'nowpayments' => filled(Arr::get($keys, 'api_key')),
            'cryptomus', 'heleket' => filled(Arr::get($keys, 'api_key'))
                && filled(Arr::get($keys, 'merchant_id')),
            default => false,
        };
    }

    public static function exists(string $code): bool
    {
        return Arr::has(config('billing.gateways', []), $code);
    }

    /** Mobile money pushes a prompt to a handset, so it must ask for a number. */
    public static function needsPhone(string $code): bool
    {
        return config("billing.gateways.{$code}.type") === 'mobile';
    }

    /**
     * What this gateway is charged in, which is not always what we priced in.
     */
    public static function chargeCurrency(string $code): string
    {
        return (string) config(
            "billing.gateways.{$code}.charge_currency",
            config('billing.currency', 'USD'),
        );
    }

    /**
     * The USD amount converted into whatever the gateway settles in.
     *
     * TZS has no minor unit in practice, so it rounds up to a whole shilling —
     * rounding down would undercharge, and mobile money will not take
     * fractions.
     */
    public static function chargeAmount(string $code, int $cents): string
    {
        if ($code !== 'snippe') {
            return Pricing::toAmount($cents);
        }

        $rate = (float) config('services.billing.snippe.usd_to_tzs', 2600);

        return (string) (int) ceil(($cents / 100) * $rate);
    }

    public static function make(string $code): SnippeClient|NowPaymentsClient|CryptomusClient|null
    {
        $keys = config("services.billing.{$code}", []);

        return match ($code) {
            'snippe' => new SnippeClient(
                (string) Arr::get($keys, 'api_key'),
                (string) Arr::get($keys, 'webhook_secret'),
            ),
            'nowpayments' => new NowPaymentsClient(
                (string) Arr::get($keys, 'api_key'),
                (string) Arr::get($keys, 'ipn_secret'),
                (string) Arr::get($keys, 'pay_currency', 'usdttrc20'),
            ),
            'cryptomus' => new CryptomusClient(
                (string) Arr::get($keys, 'api_key'),
                (string) Arr::get($keys, 'merchant_id'),
            ),
            'heleket' => new HeleketClient(
                (string) Arr::get($keys, 'api_key'),
                (string) Arr::get($keys, 'merchant_id'),
            ),
            default => null,
        };
    }
}
