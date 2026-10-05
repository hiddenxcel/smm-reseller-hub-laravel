<?php

namespace App\Services\Payments;

use App\Models\BotCustomer;
use App\Models\BotPayment;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What a reseller's customers have paid them, for the Payments page.
 *
 * Money in, from the reseller's own customers — wallet top-ups and direct order
 * payments. The reseller paying the platform is SubscriptionPayment and lives on
 * Billing; the two are never mixed on one screen.
 *
 * Scoped by tenant on every query rather than leaning on the global scope: the
 * totals here are aggregates, where a missing scope leaks another reseller's
 * numbers without a single row being shown.
 */
class PaymentsQuery
{
    public const PAGE_SIZE = 25;

    /** Windows a reseller can ask for, in days; 0 is "all time". */
    public const RANGES = [7, 30, 90, 0];

    public const DEFAULT_RANGE = 30;

    public const STATUSES = ['success', 'pending', 'failed'];

    public const TYPES = ['wallet_topup', 'order_payment'];

    /** @var array{status: ?string, gateway: ?string, type: ?string, range: int, q: string} */
    private array $filters;

    public function __construct(private Tenant $tenant, array $input)
    {
        $range = (int) ($input['range'] ?? self::DEFAULT_RANGE);
        $status = $input['status'] ?? null;
        $type = $input['type'] ?? null;
        $gateway = $input['gateway'] ?? null;

        $this->filters = [
            // Anything unrecognised falls back to "no filter" rather than an
            // empty page: a hand-edited URL should still show something.
            'status' => in_array($status, self::STATUSES, true) ? $status : null,
            'type' => in_array($type, self::TYPES, true) ? $type : null,
            'gateway' => is_string($gateway) && $gateway !== '' ? $gateway : null,
            'range' => in_array($range, self::RANGES, true) ? $range : self::DEFAULT_RANGE,
            'q' => trim((string) ($input['q'] ?? '')),
        ];
    }

    public static function for(Tenant $tenant, array $input): self
    {
        return new self($tenant, $input);
    }

    /** @return array{status: ?string, gateway: ?string, type: ?string, range: int, q: string} */
    public function filters(): array
    {
        return $this->filters;
    }

    public function isFiltered(): bool
    {
        return $this->filters['status'] !== null
            || $this->filters['gateway'] !== null
            || $this->filters['type'] !== null
            || $this->filters['q'] !== ''
            || $this->filters['range'] !== self::DEFAULT_RANGE;
    }

    public function paginate(int $page): LengthAwarePaginator
    {
        return $this->filtered(withStatus: true)
            ->with(['customer:id,name,phone', 'order:id,service_name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE, ['*'], 'page', max($page, 1));
    }

    /**
     * The four figures above the list: money in, how many payments made it, and
     * what is still waiting or never arrived. Over the chosen window only — a
     * search box that changed "Received" with every keystroke would make the
     * number meaningless.
     *
     * @return array{received: float, paid: int, pending: array{amount: float, count: int}, failed: int}
     */
    public function summary(): array
    {
        $rows = $this->base()
            ->selectRaw('status, count(*) as total, coalesce(sum(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'received' => round((float) ($rows['success']->amount ?? 0), 2),
            'paid' => (int) ($rows['success']->total ?? 0),
            'pending' => [
                'amount' => round((float) ($rows['pending']->amount ?? 0), 2),
                'count' => (int) ($rows['pending']->total ?? 0),
            ],
            'failed' => (int) ($rows['failed']->total ?? 0),
        ];
    }

    /**
     * Counts for the status tabs, honouring every filter except status itself —
     * otherwise choosing "Paid" would zero the other tabs and leave nowhere to
     * click.
     *
     * @return array{all: int, success: int, pending: int, failed: int}
     */
    public function tabCounts(): array
    {
        $counts = $this->filtered(withStatus: false)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'all' => (int) $counts->sum(),
            'success' => (int) ($counts['success'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
        ];
    }

    /**
     * The gateways this reseller has actually taken money through, for the
     * filter — never the full catalogue, which would list a dozen they have
     * never used.
     *
     * @return array<int, array{code: string, label: string}>
     */
    public function gatewayOptions(): array
    {
        return BotPayment::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway')
            ->map(fn (string $code) => ['code' => $code, 'label' => self::gatewayLabel($code)])
            ->values()
            ->all();
    }

    public static function gatewayLabel(string $code): string
    {
        $label = config("gateways.{$code}.label");

        if (is_string($label) && $label !== '') {
            return $label;
        }

        $text = str_replace('_', ' ', $code);

        return ucfirst($text);
    }

    /** @return array<string, mixed> */
    public static function toRow(BotPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->transaction_ref,
            'gatewayReference' => $payment->gateway_reference,
            'gateway' => $payment->gateway,
            'gatewayLabel' => self::gatewayLabel((string) $payment->gateway),
            'type' => $payment->type,
            'amount' => (float) $payment->amount,
            'status' => $payment->status,
            'customer' => $payment->customer === null ? null : [
                'name' => $payment->customer->name,
                'phone' => $payment->customer->phone,
            ],
            'orderId' => $payment->order_id,
            'orderService' => $payment->order?->service_name,
            'createdAt' => $payment->created_at?->toIso8601String(),
        ];
    }

    // ---- internals -------------------------------------------------------

    /** The tenant's payments in the chosen window, and nothing else. */
    private function base(): Builder
    {
        $query = BotPayment::withoutTenantScope()->where('tenant_id', $this->tenant->id);

        if ($this->filters['range'] > 0) {
            $query->where('created_at', '>=', Carbon::today()->subDays($this->filters['range'] - 1));
        }

        return $query;
    }

    private function filtered(bool $withStatus): Builder
    {
        $query = $this->base();

        if ($withStatus && $this->filters['status'] !== null) {
            $query->where('status', $this->filters['status']);
        }

        if ($this->filters['gateway'] !== null) {
            $query->where('gateway', $this->filters['gateway']);
        }

        if ($this->filters['type'] !== null) {
            $query->where('type', $this->filters['type']);
        }

        if ($this->filters['q'] !== '') {
            $this->search($query, $this->filters['q']);
        }

        return $query;
    }

    /**
     * A reference, or the customer's name or number — whichever the reseller
     * has to hand when someone says "I paid".
     *
     * LIKE wildcards in the search are escaped, so "50%" looks for 50% and does
     * not match everything; `!` is the escape character because it is the one
     * both Postgres and SQLite accept without configuration.
     */
    private function search(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
        // Digits only count as a phone number when that is all the person typed.
        // A reference like SMMTOP11111 has digits in it too, and matching those
        // against every customer's number would return people who have nothing
        // to do with it.
        $digits = preg_match('/^[0-9+()\s-]{3,}$/', $term) === 1
            ? (preg_replace('/\D+/', '', $term) ?? '')
            : '';

        $query->where(function (Builder $inner) use ($like, $digits) {
            $inner->whereRaw("lower(transaction_ref) like ? escape '!'", [$like])
                ->orWhereRaw("lower(coalesce(gateway_reference, '')) like ? escape '!'", [$like])
                ->orWhereIn('customer_id', function ($sub) use ($like, $digits) {
                    $sub->select('id')
                        ->from((new BotCustomer)->getTable())
                        ->where('tenant_id', $this->tenant->id)
                        ->where(function ($match) use ($like, $digits) {
                            $match->whereRaw("lower(coalesce(name, '')) like ? escape '!'", [$like]);

                            // A phone is matched on its digits, so "0712 345" finds
                            // "+255 712 345 678" only when typed as digits both ways.
                            if ($digits !== '') {
                                $match->orWhere('phone', 'like', '%'.$digits.'%');
                            }
                        });
                });
        });
    }
}
