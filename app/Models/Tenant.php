<?php

namespace App\Models;

use App\Enums\ServiceKey;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * A tenant is an SMM reseller (this app's actual customer), not an end
 * customer of theirs (that's BotCustomer). Auth password column is
 * password_hash, not Laravel's default password — see getAuthPassword().
 */
class Tenant extends Authenticatable implements AuthenticatableContract
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'business_name',
        'email',
        'phone',
        'password_hash',
        'status',
        'lang',
        'pricing_currency',
        'pricing_currency_changed_at',
        'auto_catalogue_sync',
        'referral_code',
        'referred_by',
        'referral_credit',
        'first_payment_done',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'referral_credit' => 'decimal:2',
            'first_payment_done' => 'boolean',
            'pricing_currency_changed_at' => 'datetime',
            'auto_catalogue_sync' => 'boolean',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The name the bots sign with. Ours until the reseller has paid for the
     * Custom Brand boost, then their own.
     */
    public function brandName(): string
    {
        // Both must hold: this reseller paid, and the feature is switched on
        // in the admin console (an inactive plan turns it off for everyone).
        return Plan::forService(ServiceKey::CustomBrand) !== null
            && Subscription::isServiceActive((int) $this->id, ServiceKey::CustomBrand)
            ? (string) $this->business_name
            : (string) config('billing.default_brand', 'Auto Hub');
    }

    public function panels(): HasMany
    {
        return $this->hasMany(TenantPanel::class);
    }

    public function whatsAppNumbers(): HasMany
    {
        return $this->hasMany(TenantWhatsApp::class);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    /**
     * Spend up to $amount of referral credit, returning what was actually
     * taken.
     *
     * The `referral_credit >= ?` predicate is what stops two checkouts opened
     * at once from spending the same credit twice — do not replace it with a
     * read-then-write. Returns 0.00 when there is not enough to cover the
     * requested amount, having taken nothing.
     */
    public function spendReferralCredit(string|float $amount): string
    {
        $wanted = min((float) $amount, (float) $this->referral_credit);

        if ($wanted <= 0) {
            return '0.00';
        }

        $applied = number_format($wanted, 2, '.', '');

        $taken = static::whereKey($this->getKey())
            ->where('referral_credit', '>=', $applied)
            ->update(['referral_credit' => DB::raw("referral_credit - {$this->money($applied)}")]);

        if ($taken !== 1) {
            return '0.00';
        }

        $this->refresh();

        return $applied;
    }

    /** Give credit back — a failed or abandoned payment must not consume it. */
    public function refundReferralCredit(string|float $amount): void
    {
        if ((float) $amount <= 0) {
            return;
        }

        static::whereKey($this->getKey())
            ->update(['referral_credit' => DB::raw("referral_credit + {$this->money($amount)}")]);

        $this->refresh();
    }

    /**
     * A money amount as a SQL literal. Validated as a decimal first, so it
     * cannot carry SQL through; raw SQL is needed because the arithmetic has
     * to happen in the database for the update to be atomic.
     */
    private function money(string|float $amount): string
    {
        $value = number_format((float) $amount, 2, '.', '');

        if (! preg_match('/^-?\d{1,15}\.\d{2}$/', $value)) {
            throw new \InvalidArgumentException("Invalid money amount: {$value}");
        }

        return $this->getConnection()->getDriverName() === 'pgsql'
            ? "{$value}::numeric"
            : $value;
    }
}
