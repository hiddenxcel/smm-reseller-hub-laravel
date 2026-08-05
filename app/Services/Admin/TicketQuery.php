<?php

namespace App\Services\Admin;

use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every support ticket on the platform, across resellers.
 *
 * A ticket belongs to a reseller and holds a conversation with THEIR customer,
 * so this is a window rather than a workbench: the console can see a ticket and
 * see that it is stuck, but replying to it would put the platform's words in a
 * reseller's WhatsApp thread under their business name. Answering happens in
 * the reseller's own inbox, which is what impersonation is for.
 *
 * The state worth surfacing is `handed_over_at`: while it is set the bot stays
 * silent, so a handed-over ticket nobody has answered is a customer waiting on
 * a person who may not know they are waiting.
 */
class TicketQuery
{
    public const PAGE_SIZES = [25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 25;

    public const STATES = ['open', 'pending', 'handed_over', 'resolved', 'closed'];

    public static function make(): self
    {
        return new self;
    }

    public function paginate(TicketFilters $filters): LengthAwarePaginator
    {
        return $this->apply($filters)
            ->orderByDesc('id')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /**
     * @param  int[]  $tenantIds
     * @return array<int, string>
     */
    public function tenantNames(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return Tenant::whereIn('id', $tenantIds)->pluck('business_name', 'id')->all();
    }

    public static function toRow(Ticket $ticket, ?string $tenantName = null): array
    {
        return [
            'id' => $ticket->id,
            'tenantId' => $ticket->tenant_id,
            'tenant' => $tenantName ?? 'Deleted reseller',
            'customer' => $ticket->customer_identifier,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'handedOver' => $ticket->handed_over_at !== null,
            'handedOverAt' => $ticket->handed_over_at?->toIso8601String(),
            'lastCustomerAt' => $ticket->last_customer_at?->toIso8601String(),
            // Handed to a person over a day ago and still open: somebody is
            // waiting on a human who may not have noticed.
            'stalled' => $ticket->handed_over_at !== null
                && in_array($ticket->status, ['open', 'pending'], true)
                && $ticket->handed_over_at->lessThan(now()->subDay()),
            'at' => $ticket->created_at?->toIso8601String(),
        ];
    }

    public function tabCounts(TicketFilters $filters): array
    {
        $base = clone $filters;
        $base->state = null;

        $counts = ['all' => $this->apply($base)->count()];

        foreach (self::STATES as $state) {
            $scoped = clone $base;
            $scoped->state = $state;

            $counts[$state] = $this->apply($scoped)->count();
        }

        return $counts;
    }

    /** Resellers with the most unanswered tickets — where support load sits. */
    public function busiest(int $limit = 8): array
    {
        $counts = Ticket::withoutTenantScope()
            ->openish()
            ->selectRaw('tenant_id, count(*) as total')
            ->groupBy('tenant_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'tenant_id');

        $names = $this->tenantNames($counts->keys()->all());

        return $counts
            ->map(fn ($total, $tenantId) => [
                'tenantId' => (int) $tenantId,
                'tenant' => $names[$tenantId] ?? 'Deleted reseller',
                'open' => (int) $total,
            ])
            ->values()
            ->all();
    }

    // ---- internals -------------------------------------------------------

    private function apply(TicketFilters $filters): Builder
    {
        $query = Ticket::withoutTenantScope();

        if ($filters->tenantId !== null) {
            $query->where('tenant_id', $filters->tenantId);
        }

        if ($filters->category !== null) {
            $query->where('category', $filters->category);
        }

        if ($filters->search !== null) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters->search).'%';

            $query->where(function (Builder $q) use ($term) {
                $q->where('subject', 'like', $term)
                    ->orWhere('customer_identifier', 'like', $term)
                    ->orWhereIn('tenant_id', Tenant::where('business_name', 'like', $term)->select('id'));
            });
        }

        return $this->applyState($query, $filters->state);
    }

    /**
     * `handed_over` is not a status column — it is an open ticket a person has
     * claimed. Treated as a state of its own because it is the one that needs
     * chasing.
     */
    private function applyState(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            'handed_over' => $query->whereNotNull('handed_over_at')->openish(),
            'open', 'pending', 'resolved', 'closed' => $query->where('status', $state),
            default => $query,
        };
    }
}
