<?php

namespace App\Services\Catalogue;

/**
 * One bulk price change, described independently of what it is applied to.
 *
 * Kept as its own object so the preview and the apply cannot diverge: both
 * call `applyTo`, and there is no second implementation of the arithmetic for
 * one of them to drift away from.
 */
final readonly class BulkAdjustment
{
    private const SCALE = 6;

    /** Add or subtract a percentage of the current price. */
    public const PERCENT = 'percent';

    /** Add or subtract a flat amount per 1,000. */
    public const FIXED = 'fixed';

    /** Set every price to the same number. */
    public const SET = 'set';

    /** Re-derive the price from cost, as a markup. */
    public const MARKUP = 'markup';

    public const MODES = [self::PERCENT, self::FIXED, self::SET, self::MARKUP];

    private function __construct(
        public string $mode,
        public string $amount,
        /** True to subtract rather than add. Ignored by `set` and `markup`. */
        public bool $decrease = false,
        /** Never price below cost plus this, when a cost is known. */
        public ?string $minProfit = null,
    ) {}

    public static function make(
        string $mode,
        string $amount,
        bool $decrease = false,
        ?string $minProfit = null,
    ): self {
        return new self(
            mode: in_array($mode, self::MODES, true) ? $mode : self::PERCENT,
            amount: $amount,
            decrease: $decrease,
            minProfit: $minProfit,
        );
    }

    /**
     * The new price for one service.
     *
     * `markup` needs the cost and returns the price unchanged without one —
     * a service the panel never priced cannot be marked up from nothing, and
     * guessing would be worse than leaving it alone.
     */
    public function applyTo(string $currentPrice, ?string $cost): string
    {
        $price = match ($this->mode) {
            self::PERCENT => $this->byPercent($currentPrice),
            self::FIXED => $this->byFixed($currentPrice),
            self::SET => $this->amount,
            self::MARKUP => $cost === null
                ? $currentPrice
                : bcadd($cost, bcdiv(bcmul($cost, $this->amount, self::SCALE), '100', self::SCALE), self::SCALE),
            default => $currentPrice,
        };

        // The floor is what stops a careless "-40%" from selling at a loss.
        if ($this->minProfit !== null && $cost !== null) {
            $floor = bcadd($cost, $this->minProfit, self::SCALE);

            if (bccomp($price, $floor, self::SCALE) === -1) {
                $price = $floor;
            }
        }

        if (bccomp($price, '0', self::SCALE) === -1) {
            return '0.0000';
        }

        return bcadd($price, '0', 4);
    }

    private function byPercent(string $price): string
    {
        $delta = bcdiv(bcmul($price, $this->amount, self::SCALE), '100', self::SCALE);

        return $this->decrease
            ? bcsub($price, $delta, self::SCALE)
            : bcadd($price, $delta, self::SCALE);
    }

    private function byFixed(string $price): string
    {
        return $this->decrease
            ? bcsub($price, $this->amount, self::SCALE)
            : bcadd($price, $this->amount, self::SCALE);
    }

    /** How the adjustment reads in the confirmation and the history note. */
    public function describe(): string
    {
        $amount = rtrim(rtrim($this->amount, '0'), '.');
        $sign = $this->decrease ? '-' : '+';

        return match ($this->mode) {
            self::PERCENT => "{$sign}{$amount}%",
            self::FIXED => "{$sign}{$amount}",
            self::SET => "set to {$amount}",
            self::MARKUP => "cost +{$amount}%",
            default => 'unchanged',
        };
    }
}
