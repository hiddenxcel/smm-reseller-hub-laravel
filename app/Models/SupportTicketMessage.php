<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message on a support thread, from either side.
 *
 * Scoped through its ticket rather than directly: the table has no tenant_id,
 * matching how {@see TicketMessage} hangs off its own ticket.
 */
class SupportTicketMessage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'support_ticket_id',
        'superadmin_id',
        'author',
        'body',
        'internal',
    ];

    protected function casts(): array
    {
        return [
            'internal' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Superadmin::class, 'superadmin_id');
    }

    /**
     * What the reseller is allowed to see.
     *
     * Applied in the query rather than filtered after loading: an internal note
     * that reaches the browser has leaked even if React never renders it.
     */
    public function scopeVisibleToTenant(Builder $query): Builder
    {
        return $query->where('internal', false);
    }
}
