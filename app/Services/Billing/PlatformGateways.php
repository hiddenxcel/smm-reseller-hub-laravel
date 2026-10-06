<?php

namespace App\Services\Billing;

use App\Models\PlatformGatewayCredential;
use App\Services\Payments\BinancePayClient;
use App\Services\Payments\CryptomusClient;
use App\Services\Payments\FimipayClient;
use App\Services\Payments\GatewayFamilies;
use App\Services\Payments\HeleketClient;
use App\Services\Payments\NowPaymentsClient;
use App\Services\Payments\PaymentGateway;
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
                'needsPhone' => self::needsPhone($code),
            ])
            ->values()
            ->all();
    }

    public static function isConfigured(string $code): bool
    {
        $keys = self::keys($code);

        if (FimipayClient::isFimipay($code)) {
            return filled(Arr::get($keys, 'api_key'));
        }

        return match ($code) {
            'snippe', 'snippe_ke', 'snippe_ug' => filled(Arr::get($keys, 'api_key')),
            'nowpayments' => filled(Arr::get($keys, 'api_key')),
            'cryptomus', 'heleket' => filled(Arr::get($keys, 'api_key'))
                && filled(Arr::get($keys, 'merchant_id')),
            // Both halves are needed before it is offered: the secret is not
            // merely for webhooks here, it signs the order request too, so a
            // key on its own cannot even reach the checkout.
            'binance' => filled(Arr::get($keys, 'api_key'))
                && filled(Arr::get($keys, 'webhook_secret')),
            default => false,
        };
    }

    /**
     * The credentials for a gateway: environment first, database second.
     *
     * .env wins so an operator who already keeps keys there — the original
     * arrangement, and the safer one — is not overridden by anything typed
     * into the console. The database row exists because editing .env needs
     * SSH, which in practice meant the keys were never set at all and no
     * reseller could pay.
     *
     * A row that exists but is not enabled is ignored: credentials are stored
     * so they can be checked before resellers are shown the option.
     *
     * @return array<string, string|null>
     */
    public static function keys(string $code): array
    {
        if ($family = GatewayFamilies::familyOf($code)) {
            return self::familyKeys($code, $family);
        }

        $fromEnv = config("services.billing.{$code}", []);

        if (filled(Arr::get($fromEnv, 'api_key'))) {
            return $fromEnv;
        }

        $row = PlatformGatewayCredential::all()->get($code);

        if ($row === null || ! $row->enabled) {
            return $fromEnv;
        }

        return [
            ...$fromEnv,
            'api_key' => $row->api_key_enc,
            // Each gateway names its second secret differently; the row holds
            // one column and the mapping happens here rather than in four
            // places downstream.
            'webhook_secret' => $row->webhook_secret_enc,
            'ipn_secret' => $row->webhook_secret_enc,
            'merchant_id' => $row->extra_enc,
        ];
    }

    /**
     * A family is one merchant account sold in several markets (see
     * GatewayFamilies), so its keys are held once. A market is "on" when its
     * own row is enabled (or its own environment value is set); the keys it
     * uses are its own if it has any, otherwise the ones saved against any
     * other market of the family.
     *
     * @return array<string, string|null>
     */
    private static function familyKeys(string $code, string $family): array
    {
        $fromEnv = config("services.billing.{$code}", []);
        $row = PlatformGatewayCredential::all()->get($code);

        if (filled(Arr::get($fromEnv, 'api_key'))) {
            return $fromEnv;
        }

        if ($row === null || ! $row->enabled) {
            return $fromEnv;
        }

        $shared = self::sharedKeys($family);
        $hasOwn = filled($row->api_key_enc);

        return [
            ...$fromEnv,
            'api_key' => $hasOwn ? $row->api_key_enc : $shared['api_key'],
            'webhook_secret' => $hasOwn ? $row->webhook_secret_enc : $shared['webhook_secret'],
            'ipn_secret' => $hasOwn ? $row->webhook_secret_enc : $shared['webhook_secret'],
        ];
    }

    /**
     * The one set of keys for a family: an environment value if any market has
     * one, otherwise the first saved row that holds a key, switched on or not.
     *
     * @return array{api_key: ?string, webhook_secret: ?string, source: string}
     */
    public static function sharedKeys(string $family): array
    {
        foreach (GatewayFamilies::codes($family) as $code) {
            $env = config("services.billing.{$code}", []);

            if (filled(Arr::get($env, 'api_key'))) {
                return [
                    'api_key' => $env['api_key'],
                    'webhook_secret' => Arr::get($env, 'webhook_secret'),
                    'source' => 'env',
                ];
            }
        }

        foreach (GatewayFamilies::codes($family) as $code) {
            $row = PlatformGatewayCredential::all()->get($code);

            if ($row !== null && filled($row->api_key_enc)) {
                return [
                    'api_key' => $row->api_key_enc,
                    'webhook_secret' => $row->webhook_secret_enc,
                    'source' => 'database',
                ];
            }
        }

        return ['api_key' => null, 'webhook_secret' => null, 'source' => 'none'];
    }

    /**
     * A family as the console shows it: one key pair and a switch per market.
     * Never the keys themselves — only the last four characters.
     *
     * @return array<string, mixed>
     */
    public static function familyState(string $family): array
    {
        $shared = self::sharedKeys($family);
        $definition = GatewayFamilies::FAMILIES[$family];

        return [
            'family' => $family,
            'label' => $definition['label'],
            'intro' => $definition['intro'],
            'keyLabel' => $definition['keyLabel'],
            'secretLabel' => $definition['secretLabel'],
            'secretRequired' => $definition['secretRequired'],
            'source' => $shared['source'],
            'keySaved' => filled($shared['api_key']),
            'webhookSecretSaved' => filled($shared['webhook_secret']),
            'hint' => filled($shared['api_key']) ? '…'.mb_substr((string) $shared['api_key'], -4) : null,
            'markets' => array_map(function (string $code) {
                $row = PlatformGatewayCredential::all()->get($code);

                return [
                    'code' => $code,
                    'label' => GatewayFamilies::marketLabel($code),
                    'on' => filled(Arr::get(config("services.billing.{$code}", []), 'api_key'))
                        || ($row !== null && $row->enabled),
                ];
            }, GatewayFamilies::codes($family)),
        ];
    }
    public static function exists(string $code): bool
    {
        return Arr::has(config('billing.gateways', []), $code);
    }

    /**
     * Where this gateway's credentials came from.
     *
     * Worth showing: an owner who edits a key in the console and sees no
     * change needs to know an .env value is winning, rather than concluding
     * the save did not work.
     */
    public static function source(string $code): string
    {
        if (filled(Arr::get(config("services.billing.{$code}", []), 'api_key'))) {
            return 'env';
        }

        $row = PlatformGatewayCredential::all()->get($code);

        if ($row === null) {
            return 'none';
        }

        return $row->enabled ? 'database' : 'stored-disabled';
    }

    /**
     * The last four characters of the key, and nothing else.
     *
     * Enough to tell two keys apart when rotating one; useless to anyone who
     * captures the screen.
     */
    public static function hint(string $code): ?string
    {
        $key = (string) Arr::get(self::keys($code), 'api_key');

        return $key === '' ? null : '…'.mb_substr($key, -4);
    }

    /** Mobile money pushes a prompt to a handset, so it must ask for a number. */
    public static function needsPhone(string $code): bool
    {
        return (bool) config(
            "billing.gateways.{$code}.needs_phone",
            config("billing.gateways.{$code}.type") === 'mobile',
        );
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

    /**
     * The platform's own client for a gateway, built from config rather than
     * from a reseller's stored credentials — this is how resellers pay us.
     */
    public static function make(string $code): ?PaymentGateway
    {
        $keys = self::keys($code);

        if (FimipayClient::isFimipay($code)) {
            return new FimipayClient(
                (string) Arr::get($keys, 'api_key'),
                (string) Arr::get($keys, 'webhook_secret'),
                $code,
            );
        }

        return match ($code) {
            'snippe', 'snippe_ke', 'snippe_ug' => new SnippeClient(
                (string) Arr::get($keys, 'api_key'),
                (string) Arr::get($keys, 'webhook_secret'),
                match ($code) {
                    'snippe_ke' => 'KES',
                    'snippe_ug' => 'UGX',
                    default => 'TZS',
                },
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
            'binance' => new BinancePayClient(
                (string) Arr::get($keys, 'api_key'),
                (string) Arr::get($keys, 'webhook_secret'),
            ),
            default => null,
        };
    }
}
