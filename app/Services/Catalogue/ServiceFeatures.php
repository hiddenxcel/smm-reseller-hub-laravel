<?php

namespace App\Services\Catalogue;

/**
 * What a service's name already promises, as the short words a customer reads.
 *
 * Panels put the promises in the name: "Instagram Followers | No Drop | 365
 * Days Refill". Reading those out saves the reseller typing them again for
 * hundreds of imported services — who can then change the wording to their own.
 * Only what the name says outright is taken; a service whose name is silent
 * about drop or refill is left blank rather than guessed at, because telling a
 * customer "no drop" about a service that does drop is worse than saying
 * nothing.
 */
final class ServiceFeatures
{
    /**
     * @return array{drop_info: ?string, refill_info: ?string}
     */
    public static function guess(string $name): array
    {
        return [
            'drop_info' => preg_match('/\b(no|non|zero)[\s\-_]?drop\b/iu', $name) === 1 ? 'No drop' : null,
            'refill_info' => self::guessRefill($name),
        ];
    }

    private static function guessRefill(string $name): ?string
    {
        if (preg_match('/\bno[\s\-_]?refill\b/iu', $name) === 1) {
            return 'No refill';
        }

        // "365 Days Refill", "30d refill", "30 Day Guarantee"
        if (preg_match('/\b(\d{1,4})\s*(?:days?|d)\b[\s\-_]*(?:refill|guarantee|guaranteed|warranty)/iu', $name, $m) === 1
            // "Refill 30 days", "Guarantee: 60 days"
            || preg_match('/(?:refill|guarantee|guaranteed|warranty)[\s:\-_]*(\d{1,4})\s*(?:days?|d)\b/iu', $name, $m) === 1
            // The panels' shorthand: R30, R365
            || preg_match('/\bR(30|60|90|120|180|365)\b/u', $name, $m) === 1) {
            $days = (int) $m[1];

            return $days > 0 ? "{$days} days" : null;
        }

        if (preg_match('/\b(?:lifetime|life[\s\-_]?time)\b/iu', $name) === 1
            && preg_match('/refill|guarantee|warranty/iu', $name) === 1) {
            return 'Lifetime';
        }

        return null;
    }
}