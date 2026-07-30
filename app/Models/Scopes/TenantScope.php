<?php

namespace App\Models\Scopes;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the authenticated tenant.
 *
 * Outside a tenant session (cron, webhooks, super-admin) there is no tenant to
 * scope to, so the scope is a no-op — those contexts are expected to be
 * explicit about which tenant they act on. Callers that must cross tenants
 * inside a tenant session use Model::withoutTenantScope().
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var class-string<BelongsToTenant> $class */
        $class = $model::class;

        $tenantId = $class::currentTenantId();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
    }
}
