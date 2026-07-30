<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant isolation, enforced by the framework rather than by discipline.
 *
 * The old platform relied on every one of ~196 raw queries remembering to
 * carry `WHERE tenant_id = ?`. One forgotten clause leaked another reseller's
 * customers and API keys. Here the scope is applied globally: a query has to
 * opt OUT explicitly (withoutTenantScope) to cross the boundary, and the
 * places that legitimately do so (webhooks resolving a payment by reference,
 * cron jobs, super-admin reporting) are few and reviewable.
 *
 * tenant_id is always taken from the authenticated session, never from input.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // Stamp tenant_id on create so callers never pass it from request data.
        static::creating(function ($model) {
            if ($model->tenant_id === null && ($tenantId = static::currentTenantId()) !== null) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    /** The authenticated tenant's id, or null outside a tenant session (cron, webhook, super-admin). */
    public static function currentTenantId(): ?int
    {
        $tenant = Auth::guard('tenant')->user();

        return $tenant?->id;
    }

    /** Escape hatch for webhooks/cron/super-admin. Use deliberately and sparingly. */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
