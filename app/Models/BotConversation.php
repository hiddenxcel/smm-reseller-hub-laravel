<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Bot state machine row: (tenant, phone, bot_type) -> state + JSON context.
 * The context carries the in-flight order being built, so its shape is a live
 * data contract for the handlers.
 */
class BotConversation extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    /** Conversations older than this are swept by the cleanup job. */
    public const TTL_MINUTES = 30;

    protected $fillable = [
        'tenant_id',
        'customer_phone',
        'bot_type',
        'state',
        'context',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
