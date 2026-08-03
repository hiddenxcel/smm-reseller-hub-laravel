<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An end customer of a reseller's shop (not a tenant). Holds the wallet
 * balance that Order Bot debits when an order is placed.
 */
class BotCustomer extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'phone',
        'name',
        'email',
        'lang',
        'country',
        'notes',
        'tags',
        'blocked_at',
        'last_seen_at',
        'balance',
        'total_spent',
        'referral_code',
        'referred_by',
        'referral_earnings',
        'first_deposit_done',
        'last_payment_phone',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'total_spent' => 'decimal:2',
            'referral_earnings' => 'decimal:2',
            'first_deposit_done' => 'boolean',
            'tags' => 'array',
            'blocked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(BotOrder::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BotPayment::class, 'customer_id');
    }

    /** Whoever invited them, and whoever they invited. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by');
    }

    /** A blocked customer is one the reseller has told the bot to ignore. */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * Atomically take $amount off the wallet. The `balance >= ?` predicate is
     * what prevents a negative balance under concurrent debits — do not
     * replace it with a read-then-write. Returns false if funds were
     * insufficient (nothing was charged).
     *
     * Callers must wrap this together with order creation in a transaction,
     * or a failure after the debit charges the customer for nothing.
     */
    public function debit(string|float $amount): bool
    {
        $amount = (string) $amount;

        $affected = static::withoutTenantScope()
            ->whereKey($this->getKey())
            ->where('balance', '>=', $amount)
            ->update([
                'balance' => DB::raw("balance - {$this->numeric($amount)}"),
                'total_spent' => DB::raw("total_spent + {$this->numeric($amount)}"),
            ]);

        if ($affected === 1) {
            $this->refresh();
        }

        return $affected === 1;
    }

    /** Add funds (a confirmed top-up). */
    public function credit(string|float $amount): void
    {
        static::withoutTenantScope()
            ->whereKey($this->getKey())
            ->update(['balance' => DB::raw("balance + {$this->numeric($amount)}")]);

        $this->refresh();
    }

    /**
     * Render a money amount as a SQL literal. Values are validated as
     * decimals first, so this cannot carry SQL through — raw SQL is needed
     * because the arithmetic must happen in the database for the update to be
     * atomic, and Eloquent's update() takes no bindings.
     *
     * Postgres gets an explicit ::numeric cast so the arithmetic stays exact;
     * SQLite (tests only) has no such type and takes the bare literal.
     */
    private function numeric(string|float $amount): string
    {
        $value = (string) $amount;

        if (! preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $value)) {
            throw new \InvalidArgumentException("Invalid money amount: {$value}");
        }

        return $this->getConnection()->getDriverName() === 'pgsql'
            ? "{$value}::numeric"
            : $value;
    }
}
