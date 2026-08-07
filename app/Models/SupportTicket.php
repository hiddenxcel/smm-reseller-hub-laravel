<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A reseller's request for help from the platform.
 *
 * Not to be confused with {@see Ticket}, which is a customer talking to a
 * reseller over WhatsApp. See the migration for why they are separate tables.
 *
 * Tenant-scoped like everything a reseller owns, so the console reads it
 * through withoutTenantScope() — an admin has no tenant session.
 */
class SupportTicket extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * What a reseller can file a ticket about.
     *
     * Mirrors the product's own shape rather than a generic list: routing a
     * ticket is the first thing an admin does, and "Order Bot" answers that
     * where "Technical" does not.
     */
    public const CATEGORIES = [
        'order-bot' => 'Order Bot',
        'support-bot' => 'Support Bot',
        'billing' => 'Billing & subscription',
        'payments' => 'Payments & gateways',
        'services' => 'Services & providers',
        'whatsapp' => 'WhatsApp connection',
        'bug' => 'Something is broken',
        'other' => 'Something else',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /**
     * open      — filed, nobody has answered yet
     * pending   — an admin replied and is waiting on the reseller
     * answered  — the reseller replied and is waiting on us
     * resolved  — settled, reopenable by replying
     * closed    — settled and locked
     */
    public const STATUSES = ['open', 'pending', 'answered', 'resolved', 'closed'];

    /** Statuses a reseller may still write into. */
    public const REPLYABLE = ['open', 'pending', 'answered', 'resolved'];

    protected $fillable = [
        'reference',
        'tenant_id',
        'subject',
        'category',
        'priority',
        'status',
        'last_reply_by',
        'last_reply_at',
        'first_responded_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    /**
     * A reference a reseller can read down the phone.
     *
     * Not the row id: that would tell every reseller how many tickets the
     * platform has ever taken. Collisions are retried rather than trusted to
     * chance — the unique index is the real guarantee.
     */
    public static function makeReference(): string
    {
        do {
            $reference = 'HX-'.Str::upper(Str::random(6));
        } while (static::withoutTenantScope()->where('reference', $reference)->exists());

        return $reference;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'pending', 'answered'], true);
    }

    /** Can the reseller still write on this thread? */
    public function acceptsReply(): bool
    {
        return in_array($this->status, self::REPLYABLE, true);
    }

    /** Is the platform the one holding this up? */
    public function awaitingUs(): bool
    {
        return in_array($this->status, ['open', 'answered'], true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /** Everything still needing an answer, worst first. */
    public function scopeAwaitingReply(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'answered']);
    }

    /**
     * @return array<string, int> status => count, every status present
     */
    public static function statusCounts(): array
    {
        $counts = static::withoutTenantScope()
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as total'))
            ->pluck('total', 'status');

        return array_combine(
            self::STATUSES,
            array_map(fn (string $status) => (int) ($counts[$status] ?? 0), self::STATUSES),
        );
    }
}
