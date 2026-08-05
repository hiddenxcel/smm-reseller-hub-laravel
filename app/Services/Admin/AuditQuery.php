<?php

namespace App\Services\Admin;

use App\Models\ActivityLog;
use App\Models\Superadmin;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Who did what, and when.
 *
 * The console's own record of itself. Read-only by design and by omission —
 * there is no route that edits or deletes an activity row, because a trail
 * somebody can quietly tidy is not a trail.
 *
 * Rows are never removed here. If they ever need pruning it belongs in a
 * scheduled command with a retention window, where the policy is visible,
 * rather than behind a button that deletes what an admin would rather nobody
 * read.
 */
class AuditQuery
{
    public const PAGE_SIZES = [50, 100, 200];

    public const DEFAULT_PAGE_SIZE = 50;

    public const ACTOR_TYPES = ['superadmin', 'tenant', 'system'];

    public static function make(): self
    {
        return new self;
    }

    public function paginate(AuditFilters $filters): LengthAwarePaginator
    {
        return $this->apply($filters)
            ->orderByDesc('id')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /**
     * Actor names for the page, in two queries rather than one per row.
     *
     * An admin action carries its actor's username in `details` already, so
     * only tenant actors need looking up — but admin rows written before that
     * was true fall back to the id, and resolving both keeps the column honest.
     *
     * @return array{tenants: array<int, string>, admins: array<int, string>}
     */
    public function actorNames(iterable $rows): array
    {
        $tenantIds = [];
        $adminIds = [];

        foreach ($rows as $row) {
            if ($row->actor_id === null) {
                continue;
            }

            match ($row->actor_type) {
                'tenant' => $tenantIds[] = $row->actor_id,
                'superadmin' => $adminIds[] = $row->actor_id,
                default => null,
            };
        }

        return [
            'tenants' => $tenantIds === []
                ? []
                : Tenant::whereIn('id', array_unique($tenantIds))
                    ->pluck('business_name', 'id')
                    ->all(),
            'admins' => $adminIds === []
                ? []
                : Superadmin::whereIn('id', array_unique($adminIds))
                    ->pluck('username', 'id')
                    ->all(),
        ];
    }

    /**
     * @param  array{tenants: array<int, string>, admins: array<int, string>}  $names
     */
    public static function toRow(ActivityLog $entry, array $names = []): array
    {
        $actor = match ($entry->actor_type) {
            // The username stored at the time wins: roles change and rows get
            // disabled, so an id alone stops being readable.
            'superadmin' => $entry->details['actor']
                ?? $names['admins'][$entry->actor_id]
                ?? null,
            'tenant' => $names['tenants'][$entry->actor_id] ?? null,
            default => null,
        };

        return [
            'id' => $entry->id,
            'actorType' => $entry->actor_type,
            'actorId' => $entry->actor_id,
            'actor' => $actor,
            'action' => $entry->action,
            'details' => $entry->details,
            // Present on admin actions taken against a reseller, so the row can
            // link to whoever it was done to.
            'tenantId' => isset($entry->details['tenant_id'])
                ? (int) $entry->details['tenant_id']
                : null,
            'ip' => $entry->ip,
            'at' => $entry->created_at?->toIso8601String(),
        ];
    }

    /** The distinct actions recorded, for the filter dropdown. */
    public function actions(): array
    {
        return ActivityLog::query()
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }

    public function tabCounts(AuditFilters $filters): array
    {
        $base = clone $filters;
        $base->actorType = null;

        $counts = ['all' => $this->apply($base)->count()];

        foreach (self::ACTOR_TYPES as $type) {
            $scoped = clone $base;
            $scoped->actorType = $type;

            $counts[$type] = $this->apply($scoped)->count();
        }

        return $counts;
    }

    public static function parseDate(string $value, bool $endOfDay = false): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    // ---- internals -------------------------------------------------------

    private function apply(AuditFilters $filters): Builder
    {
        $query = ActivityLog::query();

        if ($filters->actorType !== null) {
            $query->where('actor_type', $filters->actorType);
        }

        if ($filters->action !== null) {
            $query->where('action', $filters->action);
        }

        if ($filters->tenantId !== null) {
            // Two ways a row belongs to a reseller: they did it, or it was done
            // to them. Both matter when answering "why did my account change?".
            $query->where(function (Builder $q) use ($filters) {
                $q->where(fn (Builder $inner) => $inner
                    ->where('actor_type', 'tenant')
                    ->where('actor_id', $filters->tenantId))
                    ->orWhere('details->tenant_id', $filters->tenantId);
            });
        }

        if ($filters->search !== null) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters->search).'%';

            $query->where(function (Builder $q) use ($term) {
                $q->where('action', 'like', $term)->orWhere('ip', 'like', $term);
            });
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', $filters->to);
        }

        return $query;
    }
}
