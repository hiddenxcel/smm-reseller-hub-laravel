<?php

namespace App\Models;

use App\Enums\ServiceKey;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A la carte: each row is ONE service_key for ONE tenant, with its own
 * starts_at / ends_at / status. A tenant may hold several.
 *
 * isServiceActive() is the single source of truth every bot run and dashboard
 * lock check goes through.
 */
class Subscription extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'service_key',
        'status',
        'starts_at',
        'ends_at',
        'auto_renew',
    ];

    protected function casts(): array
    {
        return [
            'service_key' => ServiceKey::class,
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'auto_renew' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Active = status 'active' AND (ends_at open OR still in the future). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function scopeForService(Builder $query, ServiceKey|string $service): Builder
    {
        return $query->where('service_key', $service instanceof ServiceKey ? $service->value : $service);
    }

    /**
     * THE GATE. Is a given service currently live for this tenant?
     * Sandbox deliberately does NOT pass — see isUsable() for setup pages.
     */
    public static function isServiceActive(int $tenantId, ServiceKey|string $service): bool
    {
        return static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->forService($service)
            ->active()
            ->exists();
    }

    /**
     * True if a service is in SANDBOX (reseller is setting it up but hasn't gone
     * live/paid). Sandbox is NOT live — the public gate still returns false —
     * but dashboard setup pages and self-test are unlocked.
     */
    public static function isSandbox(int $tenantId, ServiceKey|string $service): bool
    {
        return static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->forService($service)
            ->where('status', SubscriptionStatus::Sandbox)
            ->exists();
    }

    /** A service is "usable" (setup pages + self-test allowed) if active OR sandbox. */
    public static function isUsable(int $tenantId, ServiceKey|string $service): bool
    {
        return static::isServiceActive($tenantId, $service)
            || static::isSandbox($tenantId, $service);
    }

    /** serviceKey => 'active' | 'sandbox' | 'locked' — the tri-state dashboards use. */
    public static function stateMap(int $tenantId): array
    {
        $map = [];

        foreach (ServiceKey::cases() as $service) {
            $map[$service->value] = match (true) {
                static::isServiceActive($tenantId, $service) => 'active',
                static::isSandbox($tenantId, $service) => 'sandbox',
                default => 'locked',
            };
        }

        return $map;
    }
}
