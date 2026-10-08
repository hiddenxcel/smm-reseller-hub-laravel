<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reseller's own SMM panel. api_key_enc is encrypted at rest via Laravel's
 * Crypt (the old platform used a hand-rolled AES-256-GCM envelope; there is
 * no data to carry over, so we adopt the framework's).
 */
class TenantPanel extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * What counts as low when a reseller has not said otherwise.
     *
     * Low rather than generous on purpose: an unset threshold means nobody has
     * thought about this panel, and a default that fires early trains people
     * to ignore it. The reseller who cares raises it.
     */
    public const DEFAULT_LOW_BALANCE = 5.00;

    protected $fillable = [
        'tenant_id',
        'name',
        'panel_type',
        'api_url',
        'api_key_enc',
        'admin_api_url',
        'admin_api_key_enc',
        'api_version',
        'auth_method',
        'last_checked_at',
        'last_balance',
        'balance_currency',
        'low_balance_threshold',
        'services_count',
        'status',
    ];

    protected $hidden = [
        'api_key_enc',
        'admin_api_key_enc',
    ];

    protected function casts(): array
    {
        return [
            'api_key_enc' => 'encrypted',
            'admin_api_key_enc' => 'encrypted',
            'last_checked_at' => 'datetime',
            'last_balance' => 'decimal:2',
            'low_balance_threshold' => 'decimal:2',
        ];
    }

    /**
     * What counts as "running low" for this panel.
     *
     * A reseller who has never set one still gets warned — null means "use the
     * default", not "never warn". Turning the warning off entirely would be a
     * separate decision, and is deliberately not expressible here.
     */
    public function lowBalanceThreshold(): float
    {
        return $this->low_balance_threshold !== null
            ? (float) $this->low_balance_threshold
            : self::DEFAULT_LOW_BALANCE;
    }

    /**
     * Whether the last balance we read is low enough to be worth interrupting
     * someone over.
     *
     * Lives on the model so the dashboard badge and the email agree by
     * construction. A card that looks fine while a reseller is being emailed
     * about the same panel is worse than either signal on its own.
     */
    public function isLowOnFunds(): bool
    {
        return $this->last_balance !== null
            && (float) $this->last_balance <= $this->lowBalanceThreshold();
    }
}
