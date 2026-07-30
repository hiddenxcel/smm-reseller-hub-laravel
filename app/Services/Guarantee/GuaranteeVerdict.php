<?php

namespace App\Services\Guarantee;

/**
 * The outcome of matching a service name against a tenant's guarantee rules.
 */
final readonly class GuaranteeVerdict
{
    private function __construct(
        public bool $allowed,
        public ?int $days,
        public bool $lifetime,
        public ?string $matchedKeyword,
    ) {}

    /** A no-guarantee rule matched, or nothing matched at all. */
    public static function blocked(?string $matchedKeyword = null): self
    {
        return new self(allowed: false, days: null, lifetime: false, matchedKeyword: $matchedKeyword);
    }

    /** A guarantee rule matched. refill_days of 0 means lifetime. */
    public static function allowed(int $refillDays, string $matchedKeyword): self
    {
        return new self(
            allowed: true,
            days: $refillDays !== 0 ? $refillDays : null,
            lifetime: $refillDays === 0,
            matchedKeyword: $matchedKeyword,
        );
    }
}
