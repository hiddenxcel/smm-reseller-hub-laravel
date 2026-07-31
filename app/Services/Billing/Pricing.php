<?php

namespace App\Services\Billing;

use App\Enums\ServiceKey;
use App\Models\Plan;
use App\Models\PlatformNumber;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * What a reseller owes, and why.
 *
 * Money is handled as an integer number of cents throughout. A discount on a
 * float price is exactly the arithmetic that leaves a total a cent adrift
 * from the sum of its lines, and a customer who can add up their own invoice
 * notices.
 */
class Pricing
{
    /** @return array<int, array{months: int, label: string, discount: float}> */
    public static function terms(): array
    {
        return collect(config('billing.terms', []))
            ->map(fn (array $term, int $months) => [
                'months' => $months,
                'label' => $term['label'],
                'discount' => (float) $term['discount'],
            ])
            ->values()
            ->all();
    }

    public static function isValidTerm(int $months): bool
    {
        return Arr::has(config('billing.terms', []), (string) $months);
    }

    /**
     * The services actually on sale: listed in config AND priced in the
     * plans table. A service with no plan row is not offered at all, rather
     * than offered at zero.
     *
     * @return array<int, array{key: string, name: string, description: ?string, monthly: int}>
     */
    public static function sellableServices(): array
    {
        return collect(config('billing.sellable', []))
            ->map(fn (string $key) => [$key, Plan::forService($key)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->map(fn (array $pair) => [
                'key' => $pair[0],
                'name' => $pair[1]->name,
                'description' => $pair[1]->description,
                'monthly' => self::toCents($pair[1]->price_monthly),
            ])
            ->values()
            ->all();
    }

    /**
     * What one service costs for a term, in cents.
     *
     * Rounded once, at the end — discounting each month and summing would
     * drift.
     */
    public static function serviceTotal(ServiceKey|string $service, int $months): int
    {
        if (! self::isValidTerm($months)) {
            throw new InvalidArgumentException("Not a term we sell: {$months} months.");
        }

        $plan = Plan::forService($service);

        if ($plan === null) {
            throw new InvalidArgumentException('That service is not on sale.');
        }

        $gross = self::toCents($plan->price_monthly) * $months;
        $discount = (float) config("billing.terms.{$months}.discount", 0);

        return (int) round($gross * (1 - $discount));
    }

    /**
     * Price a whole cart: some services on a term, optionally a number.
     *
     * The number is bought outright, so it takes no term and is never
     * discounted — buying twelve months of a bot does not make a phone
     * number cheaper.
     *
     * @param  array<int, string>  $services
     * @return array{lines: array<int, array>, total: int, currency: string}
     */
    public static function quote(array $services, int $months, ?PlatformNumber $number = null): array
    {
        $lines = [];

        foreach ($services as $service) {
            $plan = Plan::forService($service);

            if ($plan === null) {
                throw new InvalidArgumentException("That service is not on sale: {$service}.");
            }

            $lines[] = [
                'type' => 'service',
                'key' => $service,
                'label' => $plan->name,
                'term' => self::termLabel($months),
                'months' => $months,
                'amount' => self::serviceTotal($service, $months),
            ];
        }

        if ($number !== null) {
            $lines[] = [
                'type' => 'number',
                'key' => (string) $number->id,
                'label' => "Number {$number->display_number}",
                'term' => 'One-time',
                'months' => null,
                'amount' => self::toCents($number->monthly_cost),
            ];
        }

        return [
            'lines' => $lines,
            'total' => array_sum(array_column($lines, 'amount')),
            'currency' => (string) config('billing.currency', 'USD'),
        ];
    }

    /** Cents back to the decimal string the payment tables store. */
    public static function toAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Decimal string to cents. Goes through string arithmetic rather than
     * (int) ($value * 100), which turns "20.15" into 2014 on some inputs.
     */
    public static function toCents(string|float|int $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function termLabel(int $months): string
    {
        return (string) config("billing.terms.{$months}.label", "{$months} months");
    }
}
