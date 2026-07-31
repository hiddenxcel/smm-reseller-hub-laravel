<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

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
}
