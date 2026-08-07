<?php

namespace App\Services\Catalogue;

use App\Models\BotService;
use Illuminate\Http\Request;

/**
 * The services list's filter state, parsed once from the query string.
 *
 * Same contract as the orders and customers pages: everything arrives from the
 * URL, anything unrecognised falls back to a default, and a stale link still
 * opens the page.
 */
final class ServiceFilters
{
    public function __construct(
        public ?string $status = null,
        public ?string $platform = null,
        public ?string $category = null,
        public ?int $panelId = null,
        public ?string $search = null,
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public ?string $margin = null,
        public bool $featuredOnly = false,
        public string $sort = 'name',
        public string $direction = 'asc',
        public int $perPage = ServiceQuery::DEFAULT_PAGE_SIZE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $status = $request->string('status')->toString();
        $sort = $request->string('sort')->toString();
        $margin = $request->string('margin')->toString();
        $perPage = $request->integer('per_page');
        $search = trim($request->string('q')->toString());
        $platform = trim($request->string('platform')->toString());
        $category = trim($request->string('category')->toString());

        return new self(
            status: in_array($status, BotService::STATUSES, true) ? $status : null,
            platform: $platform === '' ? null : mb_substr($platform, 0, 50),
            category: $category === '' ? null : mb_substr($category, 0, 80),
            panelId: $request->integer('panel') ?: null,
            search: $search === '' ? null : mb_substr($search, 0, 100),
            minPrice: $request->has('min_price') ? (float) $request->input('min_price') : null,
            maxPrice: $request->has('max_price') ? (float) $request->input('max_price') : null,
            margin: in_array($margin, ServiceQuery::MARGIN_BANDS, true) ? $margin : null,
            featuredOnly: $request->boolean('featured'),
            sort: array_key_exists($sort, ServiceQuery::SORTS) ? $sort : 'name',
            direction: $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc',
            perPage: in_array($perPage, ServiceQuery::PAGE_SIZES, true)
                ? $perPage
                : ServiceQuery::DEFAULT_PAGE_SIZE,
        );
    }

    public function withStatus(?string $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withoutStatus(): self
    {
        return $this->withStatus(null);
    }

    public function withoutPlatform(): self
    {
        $clone = clone $this;
        $clone->platform = null;
        $clone->category = null;

        return $clone;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'platform' => $this->platform,
            'category' => $this->category,
            'panel' => $this->panelId,
            'q' => $this->search,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'margin' => $this->margin,
            'featured' => $this->featuredOnly,
            'sort' => $this->sort,
            'dir' => $this->direction,
            'perPage' => $this->perPage,
        ];
    }

    public function isFiltered(): bool
    {
        return $this->status !== null
            || $this->platform !== null
            || $this->category !== null
            || $this->panelId !== null
            || $this->search !== null
            || $this->minPrice !== null
            || $this->maxPrice !== null
            || $this->margin !== null
            || $this->featuredOnly;
    }
}
