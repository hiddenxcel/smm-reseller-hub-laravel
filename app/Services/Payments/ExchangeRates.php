<?php

namespace App\Services\Payments;

/**
 * Turning an amount in the shop's currency into the one a gateway collects in.
 *
 * Goes through the dollar: every rate in config/currency.php is "units per
 * USD", so any pair is one division and one multiplication, and adding a
 * currency is one line rather than a row for every pairing.
 *
 * Returns null for a currency it has no rate for, never a guess. A wrong
 * rate charges a customer the wrong amount of real money; a refusal costs one
 * failed top-up and a clear message.
 */
class ExchangeRates
{
    /** Units of $currency per US dollar, or null when none is configured. */
    public static function perUsd(string $currency): ?float
    {
        $rate = config('currency.usd_to.'.strtoupper(trim($currency)));

        return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;
    }

    /**
     * Every currency a shop can be priced in, for a picker: the ones with a
     * rate, in the order they are configured, each with its name.
     *
     * @return array<int, array{code: string, name: string, perUsd: float}>
     */
    public static function catalogue(): array
    {
        $names = (array) config('currency.names', []);
        $list = [];

        foreach (array_keys((array) config('currency.usd_to', [])) as $code) {
            $rate = self::perUsd((string) $code);

            if ($rate === null) {
                continue;
            }

            $list[] = [
                'code' => (string) $code,
                'name' => (string) ($names[$code] ?? $code),
                'perUsd' => $rate,
            ];
        }

        return $list;
    }

    public static function supports(string $currency): bool
    {
        return self::perUsd($currency) !== null;
    }

    /**
     * @param  string|float  $amount  in $from
     * @return string|null a decimal string in $to, four places, or null without a rate
     */
    public static function convert(string|float $amount, string $from, string $to): ?string
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return bcadd((string) $amount, '0', 4);
        }

        $fromRate = self::perUsd($from);
        $toRate = self::perUsd($to);

        if ($fromRate === null || $toRate === null) {
            return null;
        }

        // Through the dollar, in bc so a large shop-currency amount does not
        // pick up floating-point dust on the way.
        $usd = bcdiv((string) $amount, number_format($fromRate, 8, '.', ''), 12);

        return bcmul($usd, number_format($toRate, 8, '.', ''), 4);
    }

    /**
     * Whole units, rounded to nearest. What a gateway that takes no minor unit
     * (Snippe, and FimiPay outside USD) wants.
     */
    public static function wholeUnits(string $amount): int
    {
        return (int) round((float) $amount);
    }
}
