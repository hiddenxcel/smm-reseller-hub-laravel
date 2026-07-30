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

    /**
     * The customer's current conversation, or null when there is none or it
     * has gone stale. An expired row is deleted rather than resumed — picking
     * up a half-finished order from an hour ago confuses people more than
     * starting over.
     *
     * Runs unscoped: bots are driven from webhooks, which have no session.
     */
    public static function current(int $tenantId, string $phone, string $botType): ?self
    {
        $conversation = static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $phone)
            ->where('bot_type', $botType)
            ->first();

        if ($conversation === null) {
            return null;
        }

        if ($conversation->expires_at !== null && $conversation->expires_at->isPast()) {
            $conversation->delete();

            return null;
        }

        return $conversation;
    }

    /** Move the customer to a state, replacing the context and refreshing the TTL. */
    public static function put(int $tenantId, string $phone, string $botType, string $state, array $context = []): void
    {
        static::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenantId, 'customer_phone' => $phone, 'bot_type' => $botType],
            [
                'state' => $state,
                'context' => $context,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ],
        );
    }

    public static function clear(int $tenantId, string $phone, string $botType): void
    {
        static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $phone)
            ->where('bot_type', $botType)
            ->delete();
    }

    /** Scheduled cleanup: drop conversations nobody came back to. */
    public static function deleteExpired(): int
    {
        return static::withoutTenantScope()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
