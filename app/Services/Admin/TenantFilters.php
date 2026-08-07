<?php

namespace App\Services\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The tenant list's filter state, parsed once from the query string.
 *
 * Same contract as the reseller-facing lists: everything arrives from the URL,
 * so anything unrecognised falls back to a default rather than raising — a
 * stale or shared link must still open the page.
 */
final class TenantFilters
{
    public function __construct(
        public ?string $status = null,
        public ?string $search = null,
        public ?string $service = null,
        public ?string $billing = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public int $perPage = TenantQuery::DEFAULT_PAGE_SIZE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $status = $request->string('status')->toString();
        $service = $request->string('service')->toString();
        $billing = $request->string('billing')->toString();
        $sort = $request->string('sort')->toString();
        $perPage = $request->integer('per_page');
        $search = trim($request->string('q')->toString());

        return new self(
            status: in_array($status, ['active', 'suspended'], true) ? $status : null,
            search: $search === '' ? null : mb_substr($search, 0, 100),
            service: in_array($service, TenantQuery::serviceKeys(), true) ? $service : null,
            billing: in_array($billing, TenantQuery::BILLING_STATES, true) ? $billing : null,
            from: TenantQuery::parseDate($request->string('from')->toString()),
            to: TenantQuery::parseDate($request->string('to')->toString(), endOfDay: true),
            sort: array_key_exists($sort, TenantQuery::SORTS) ? $sort : 'created_at',
            direction: $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc',
            perPage: in_array($perPage, TenantQuery::PAGE_SIZES, true)
                ? $perPage
                : TenantQuery::DEFAULT_PAGE_SIZE,
        );
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'q' => $this->search,
            'service' => $this->service,
            'billing' => $this->billing,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'sort' => $this->sort,
            'dir' => $this->direction,
            'perPage' => $this->perPage,
        ];
    }

    public function isFiltered(): bool
    {
        return $this->status !== null
            || $this->search !== null
            || $this->service !== null
            || $this->billing !== null
            || $this->from !== null
            || $this->to !== null;
    }
}
