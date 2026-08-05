<?php

namespace App\Services\Admin;

use Illuminate\Http\Request;

/**
 * The subscription list's filter state, parsed once from the query string.
 *
 * Same contract as the other lists: everything arrives from the URL, so
 * anything unrecognised falls back to a default rather than raising — a stale
 * or shared link must still open the page.
 */
final class SubscriptionFilters
{
    public function __construct(
        public ?string $state = null,
        public ?string $service = null,
        public ?string $search = null,
        public ?int $tenantId = null,
        public string $sort = 'ends_at',
        public string $direction = 'asc',
        public int $perPage = SubscriptionQuery::DEFAULT_PAGE_SIZE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $state = $request->string('state')->toString();
        $service = $request->string('service')->toString();
        $sort = $request->string('sort')->toString();
        $perPage = $request->integer('per_page');
        $tenantId = $request->integer('tenant');
        $search = trim($request->string('q')->toString());

        return new self(
            state: in_array($state, SubscriptionQuery::STATES, true) ? $state : null,
            service: in_array($service, TenantQuery::serviceKeys(), true) ? $service : null,
            search: $search === '' ? null : mb_substr($search, 0, 100),
            tenantId: $tenantId > 0 ? $tenantId : null,
            sort: array_key_exists($sort, SubscriptionQuery::SORTS) ? $sort : 'ends_at',
            direction: $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc',
            perPage: in_array($perPage, SubscriptionQuery::PAGE_SIZES, true)
                ? $perPage
                : SubscriptionQuery::DEFAULT_PAGE_SIZE,
        );
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'service' => $this->service,
            'q' => $this->search,
            'tenant' => $this->tenantId,
            'sort' => $this->sort,
            'dir' => $this->direction,
            'perPage' => $this->perPage,
        ];
    }

    public function isFiltered(): bool
    {
        return $this->state !== null
            || $this->service !== null
            || $this->search !== null
            || $this->tenantId !== null;
    }
}
