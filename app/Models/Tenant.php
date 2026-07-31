<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * A tenant is an SMM reseller (this app's actual customer), not an end
 * customer of theirs (that's BotCustomer). Auth password column is
 * password_hash, not Laravel's default password — see getAuthPassword().
 */
class Tenant extends Authenticatable implements AuthenticatableContract
{
    use HasFactory;

    protected $fillable = [
        'business_name',
        'email',
        'phone',
        'password_hash',
        'status',
        'lang',
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
