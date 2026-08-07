<?php

namespace App\Services\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The audit list's filter state, parsed once from the query string.
 */
final class AuditFilters
{
    public function __construct(
        public ?string $actorType = null,
        public ?string $action = null,
        public ?string $search = null,
        public ?int $tenantId = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
        public int $perPage = AuditQuery::DEFAULT_PAGE_SIZE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $actorType = $request->string('actor')->toString();
        $action = trim($request->string('action')->toString());
        $tenantId = $request->integer('tenant');
        $perPage = $request->integer('per_page');
        $search = trim($request->string('q')->toString());

        return new self(
            actorType: in_array($actorType, AuditQuery::ACTOR_TYPES, true) ? $actorType : null,
            action: $action === '' ? null : mb_substr($action, 0, 100),
            search: $search === '' ? null : mb_substr($search, 0, 100),
            tenantId: $tenantId > 0 ? $tenantId : null,
            from: AuditQuery::parseDate($request->string('from')->toString()),
            to: AuditQuery::parseDate($request->string('to')->toString(), endOfDay: true),
            perPage: in_array($perPage, AuditQuery::PAGE_SIZES, true)
                ? $perPage
                : AuditQuery::DEFAULT_PAGE_SIZE,
        );
    }

    public function toArray(): array
    {
        return [
            'actor' => $this->actorType,
            'action' => $this->action,
            'q' => $this->search,
            'tenant' => $this->tenantId,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'perPage' => $this->perPage,
        ];
    }

    public function isFiltered(): bool
    {
        return $this->actorType !== null
            || $this->action !== null
            || $this->search !== null
            || $this->tenantId !== null
            || $this->from !== null
            || $this->to !== null;
    }
}
