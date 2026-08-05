<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class BlockedIp extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip',
        'reason',
        'superadmin_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(Superadmin::class, 'superadmin_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()),
        );
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Is this address refused right now?
     *
     * Cached for a minute: this is asked on every request that reaches the
     * middleware, and the list changes a handful of times a year. A minute is
     * short enough that unblocking someone feels immediate and long enough that
     * the query effectively disappears.
     */
    public static function blocks(string $ip): bool
    {
        return Cache::remember(
            "blocked-ip:{$ip}",
            now()->addMinute(),
            fn () => static::where('ip', $ip)->live()->exists(),
        );
    }

    /** Drop the cached answer for one address, after blocking or unblocking. */
    public static function forget(string $ip): void
    {
        Cache::forget("blocked-ip:{$ip}");
    }
}
