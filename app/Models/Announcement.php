<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notice the platform shows every reseller.
 *
 * Not tenant-scoped: one row is seen by everybody, which is the whole point.
 */
class Announcement extends Model
{
    use HasFactory;

    public const LEVELS = ['info', 'warning', 'critical'];

    protected $fillable = [
        'superadmin_id',
        'title',
        'body',
        'level',
        'dismissible',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissible' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Superadmin::class, 'superadmin_id');
    }

    /**
     * What a reseller should see right now: published, and not yet expired.
     *
     * A future published_at is a scheduled notice, so the comparison is against
     * now rather than a null check — writing something on Monday to appear on
     * Friday should not appear on Monday.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    public function isScheduled(): bool
    {
        return $this->published_at !== null && $this->published_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** draft | scheduled | live | expired — what the list column shows. */
    public function state(): string
    {
        return match (true) {
            $this->isDraft() => 'draft',
            $this->hasExpired() => 'expired',
            $this->isScheduled() => 'scheduled',
            default => 'live',
        };
    }
}
