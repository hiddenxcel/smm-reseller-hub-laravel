<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing markup rule: how a reseller wants cost turned into price.
 *
 * Applied on sync, so a panel raising its costs does not quietly eat the
 * reseller's margin. See PricingEngine for how a rule is matched and applied.
 */
class PricingRule extends Model
{
    use BelongsToTenant, HasFactory;

    /** Cost plus a percentage of cost. */
    public const PERCENT = 'percent';

    /** Cost plus a flat amount per 1,000. */
    public const FIXED = 'fixed';

    /** Cost times a factor. */
    public const MULTIPLIER = 'multiplier';

    public const MODES = [self::PERCENT, self::FIXED, self::MULTIPLIER];

    protected $fillable = [
        'tenant_id',
        'name',
        'platform',
        'panel_id',
        'mode',
        'amount',
        'min_profit',
        'max_profit',
        'round_to',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'min_profit' => 'decimal:4',
            'max_profit' => 'decimal:4',
            'round_to' => 'decimal:4',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(TenantPanel::class, 'panel_id');
    }

    /**
     * Does this rule cover that service?
     *
     * A null platform or panel means "any" — that is how a catch-all rule is
     * written, and how the minimum-profit floor gets applied to everything.
     */
    public function matches(BotService $service): bool
    {
        if ($this->platform !== null
            && mb_strtolower($this->platform) !== mb_strtolower((string) $service->platform)) {
            return false;
        }

        if ($this->panel_id !== null && $this->panel_id !== $service->panel_id) {
            return false;
        }

        return true;
    }

    /** How the rule reads on screen, in one line. */
    public function describe(): string
    {
        $amount = rtrim(rtrim(number_format((float) $this->amount, 4, '.', ''), '0'), '.');

        return match ($this->mode) {
            self::PERCENT => "cost +{$amount}%",
            self::FIXED => "cost +{$amount}",
            self::MULTIPLIER => "cost × {$amount}",
            default => 'cost',
        };
    }
}
