<?php

namespace App\Services\Guarantee;

/**
 * What to do with a customer's refill request, and why.
 */
final readonly class RefillDecision
{
    public const ALLOW = 'allow';

    public const REFUSE = 'refuse';

    /** Not ours to decide: a person should look at it. */
    public const HUMAN = 'human';

    /** The service promised a refill, but the order is older than the promise. */
    public const EXPIRED = 'expired';

    private function __construct(
        public string $outcome,
        public ?int $days = null,
        public bool $lifetime = false,
        /** Where the answer came from: rule, service, name or default. */
        public string $source = 'default',
        public ?int $ageDays = null,
    ) {}

    public static function allow(?int $days, bool $lifetime, string $source): self
    {
        return new self(self::ALLOW, $days, $lifetime, $source);
    }

    public static function refuse(string $source): self
    {
        return new self(self::REFUSE, source: $source);
    }

    public static function human(): self
    {
        return new self(self::HUMAN);
    }

    public static function expired(int $days, int $ageDays, string $source): self
    {
        return new self(self::EXPIRED, $days, false, $source, $ageDays);
    }

    public function allowed(): bool
    {
        return $this->outcome === self::ALLOW;
    }
}
