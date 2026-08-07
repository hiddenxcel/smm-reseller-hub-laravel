<?php

namespace App\Services\Customers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The customers list's filter state, parsed once from the query string.
 *
 * Same contract as OrderFilters: everything arrives from the URL, so anything
 * unrecognised falls back to a default rather than raising. A stale or shared
 * link must still open the page.
 */
final class CustomerFilters
{
    public function __construct(
        public ?string $segment = null,
        public ?string $search = null,
        public ?string $bot = null,
        public ?string $country = null,
        public ?string $tag = null,
        public ?string $walletBand = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
        public string $sort = 'last_seen_at',
        public string $direction = 'desc',
        public int $perPage = CustomerQuery::DEFAULT_PAGE_SIZE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $segment = $request->string('segment')->toString();
        $bot = $request->string('bot')->toString();
        $wallet = $request->string('wallet')->toString();
        $sort = $request->string('sort')->toString();
        $perPage = $request->integer('per_page');
        $country = mb_strtoupper(trim($request->string('country')->toString()));
        $search = trim($request->string('q')->toString());
        $tag = trim($request->string('tag')->toString());

        return new self(
            segment: CustomerSegment::isValid($segment) ? $segment : null,
            search: $search === '' ? null : mb_substr($search, 0, 100),
            bot: in_array($bot, ['order', 'support', 'both'], true) ? $bot : null,
            country: preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
            tag: $tag === '' ? null : mb_substr($tag, 0, 40),
            walletBand: in_array($wallet, CustomerQuery::WALLET_BANDS, true) ? $wallet : null,
            from: CustomerQuery::parseDate($request->string('from')->toString()),
            to: CustomerQuery::parseDate($request->string('to')->toString(), endOfDay: true),
            sort: array_key_exists($sort, CustomerQuery::SORTS) ? $sort : 'last_seen_at',
            direction: $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc',
            perPage: in_array($perPage, CustomerQuery::PAGE_SIZES, true)
                ? $perPage
                : CustomerQuery::DEFAULT_PAGE_SIZE,
        );
    }

    public function withSegment(?string $segment): self
    {
        $clone = clone $this;
        $clone->segment = $segment;

        return $clone;
    }

    public function withoutSegment(): self
    {
        return $this->withSegment(null);
    }

    public function toArray(): array
    {
        return [
            'segment' => $this->segment,
            'q' => $this->search,
            'bot' => $this->bot,
            'country' => $this->country,
            'tag' => $this->tag,
            'wallet' => $this->walletBand,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'sort' => $this->sort,
            'dir' => $this->direction,
            'perPage' => $this->perPage,
        ];
    }

    public function isFiltered(): bool
    {
        return $this->segment !== null
            || $this->search !== null
            || $this->bot !== null
            || $this->country !== null
            || $this->tag !== null
            || $this->walletBand !== null
            || $this->from !== null
            || $this->to !== null;
    }
}
