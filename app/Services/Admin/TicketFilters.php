<?php

namespace App\Services\Admin;

use Illuminate\Http\Request;

/**
 * The ticket list's filter state, parsed once from the query string.
 */
final class TicketFilters
{
    public function __construct(
        public ?string $state = null,
        public ?string $category = null,
        public ?string $search = null,
        public ?int $tenantId = null,
        public int $perPage = TicketQuery::DEFAULT_PAGE_SIZE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $state = $request->string('state')->toString();
        $category = trim($request->string('category')->toString());
        $tenantId = $request->integer('tenant');
        $perPage = $request->integer('per_page');
        $search = trim($request->string('q')->toString());

        return new self(
            state: in_array($state, TicketQuery::STATES, true) ? $state : null,
            // Categories are written by the bot handlers, not a fixed list
            // here; an unknown one simply matches nothing.
            category: $category === '' ? null : mb_substr($category, 0, 50),
            search: $search === '' ? null : mb_substr($search, 0, 100),
            tenantId: $tenantId > 0 ? $tenantId : null,
            perPage: in_array($perPage, TicketQuery::PAGE_SIZES, true)
                ? $perPage
                : TicketQuery::DEFAULT_PAGE_SIZE,
        );
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'category' => $this->category,
            'q' => $this->search,
            'tenant' => $this->tenantId,
            'perPage' => $this->perPage,
        ];
    }

    public function isFiltered(): bool
    {
        return $this->state !== null
            || $this->category !== null
            || $this->search !== null
            || $this->tenantId !== null;
    }
}
