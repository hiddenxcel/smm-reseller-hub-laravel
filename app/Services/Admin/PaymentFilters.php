<?php

namespace App\Services\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The payments list's filter state, parsed once from the query string.
 */
final class PaymentFilters
{
    public function __construct(
        public ?string $status = null,
        public ?string $gateway = null,
        public ?string $search = null,
        public ?int $tenantId = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public int $perPage = PaymentQuery::DEFAULT_PAGE_SIZE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $status = $request->string('status')->toString();
        $gateway = $request->string('gateway')->toString();
        $sort = $request->string('sort')->toString();
        $perPage = $request->integer('per_page');
        $tenantId = $request->integer('tenant');
        $search = trim($request->string('q')->toString());

        return new self(
            status: in_array($status, PaymentQuery::STATUSES, true) ? $status : null,
            // Not validated against a fixed list: the set of gateways grows in
            // config, and an unknown one simply matches nothing.
            gateway: $gateway === '' ? null : mb_substr($gateway, 0, 30),
            search: $search === '' ? null : mb_substr($search, 0, 120),
            tenantId: $tenantId > 0 ? $tenantId : null,
            from: PaymentQuery::parseDate($request->string('from')->toString()),
            to: PaymentQuery::parseDate($request->string('to')->toString(), endOfDay: true),
            sort: array_key_exists($sort, PaymentQuery::SORTS) ? $sort : 'created_at',
            direction: $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc',
            perPage: in_array($perPage, PaymentQuery::PAGE_SIZES, true)
                ? $perPage
                : PaymentQuery::DEFAULT_PAGE_SIZE,
        );
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'gateway' => $this->gateway,
            'q' => $this->search,
            'tenant' => $this->tenantId,
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
            || $this->gateway !== null
            || $this->search !== null
            || $this->tenantId !== null
            || $this->from !== null
            || $this->to !== null;
    }
}
