<?php

namespace App\Services\Orders;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The orders list's filter state, parsed once from the query string.
 *
 * Everything here arrives from the URL, so every field is validated on the way
 * in and anything unrecognised falls back to a default rather than raising —
 * a shared or stale link should still open the page.
 */
final class OrderFilters
{
    public function __construct(
        public ?string $status = null,
        public ?string $paymentStatus = null,
        public ?string $search = null,
        public ?int $panelId = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public int $perPage = OrderQuery::DEFAULT_PAGE_SIZE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $status = $request->string('status')->toString();
        $payment = $request->string('payment')->toString();
        $sort = $request->string('sort')->toString();
        $perPage = $request->integer('per_page');

        $search = trim($request->string('q')->toString());

        return new self(
            status: OrderStatus::isValidGroup($status) ? $status : null,
            paymentStatus: in_array($payment, ['pending', 'paid', 'failed'], true) ? $payment : null,
            // A long "search" is either a paste accident or someone probing;
            // either way there is no order id or phone number that shape.
            search: $search === '' ? null : mb_substr($search, 0, 100),
            panelId: $request->integer('panel') ?: null,
            from: OrderQuery::parseDate($request->string('from')->toString()),
            to: OrderQuery::parseDate($request->string('to')->toString(), endOfDay: true),
            sort: array_key_exists($sort, OrderQuery::SORTS) ? $sort : 'created_at',
            direction: $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc',
            perPage: in_array($perPage, OrderQuery::PAGE_SIZES, true)
                ? $perPage
                : OrderQuery::DEFAULT_PAGE_SIZE,
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

    /** What the front end echoes back into the controls. */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'payment' => $this->paymentStatus,
            'q' => $this->search,
            'panel' => $this->panelId,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'sort' => $this->sort,
            'dir' => $this->direction,
            'perPage' => $this->perPage,
        ];
    }

    /** True when anything beyond the default view is applied. */
    public function isFiltered(): bool
    {
        return $this->status !== null
            || $this->paymentStatus !== null
            || $this->search !== null
            || $this->panelId !== null
            || $this->from !== null
            || $this->to !== null;
    }
}
