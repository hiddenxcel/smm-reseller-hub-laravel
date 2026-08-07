<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Ticket extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * WhatsApp only allows a free-form reply within 24 hours of the customer's
     * last message. After that a reply needs an approved template, which the
     * inbox does not send — so it warns instead of failing silently.
     */
    public const REPLY_WINDOW_HOURS = 24;

    protected $fillable = [
        'tenant_id',
        'customer_identifier',
        'category',
        'subcategory',
        'order_ref',
        'subject',
        'status',
        'priority',
        'handed_over_at',
        'last_customer_at',
    ];

    protected function casts(): array
    {
        return [
            'handed_over_at' => 'datetime',
            'last_customer_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /** Is a person answering this conversation right now? */
    public function isHandedOver(): bool
    {
        return $this->handed_over_at !== null;
    }

    /** Can staff still send a free-form WhatsApp reply? */
    public function withinReplyWindow(): bool
    {
        return $this->last_customer_at !== null
            && $this->last_customer_at->gt(now()->subHours(self::REPLY_WINDOW_HOURS));
    }

    /**
     * The live handoff for a phone, if there is one.
     *
     * This is the question the bot asks on every inbound message, so it is a
     * single indexed lookup rather than a join: while it returns a ticket, the
     * bot must not answer.
     */
    public static function handoffFor(int $tenantId, string $phone): ?self
    {
        return static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_identifier', $phone)
            ->whereNotNull('handed_over_at')
            ->whereIn('status', ['open', 'pending'])
            ->latest('handed_over_at')
            ->first();
    }

    /**
     * The customer asked for a human. Claim the conversation for staff.
     *
     * Reuses an open human ticket rather than opening a second one: a customer
     * who types 5 twice is still one conversation, and two tickets would split
     * the thread across two screens. `handed_over_at` is only stamped when it
     * is not already set, so asking again does not reset the clock.
     */
    public static function openHandoff(int $tenantId, string $phone, ?string $subject = null): self
    {
        $ticket = static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_identifier', $phone)
            ->where('category', 'human')
            ->whereIn('status', ['open', 'pending'])
            ->latest('id')
            ->first();

        if ($ticket === null) {
            return static::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'customer_identifier' => $phone,
                'category' => 'human',
                'subject' => $subject ?? 'Customer asked for a human agent',
                'status' => 'open',
                'priority' => 'normal',
                'handed_over_at' => now(),
                'last_customer_at' => now(),
            ]);
        }

        $ticket->forceFill([
            'handed_over_at' => $ticket->handed_over_at ?? now(),
            'last_customer_at' => now(),
        ])->save();

        return $ticket;
    }

    /**
     * Give the conversation back to the bot.
     *
     * Deliberately separate from resolving: staff often answer a question and
     * let the bot carry on, and just as often close a ticket that was never
     * handed over at all.
     */
    public function returnToBot(): void
    {
        $this->forceFill(['handed_over_at' => null])->save();
    }

    /** Stamp an inbound message so the reply window can be judged. */
    public function touchCustomerMessage(): void
    {
        $this->forceFill(['last_customer_at' => now()])->save();
    }

    /** @return array<string, int> status => count, every status present */
    public static function statusCounts(int $tenantId): array
    {
        $counts = static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as total'))
            ->pluck('total', 'status');

        return [
            'open' => (int) ($counts['open'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'resolved' => (int) ($counts['resolved'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
        ];
    }

    public function scopeOpenish(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'pending']);
    }
}
