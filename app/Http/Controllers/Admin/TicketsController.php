<?php

namespace App\Http\Controllers\Admin;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Admin\TicketFilters;
use App\Services\Admin\TicketQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every support ticket on the platform.
 *
 * Read-only, deliberately. A ticket holds a conversation between a reseller and
 * THEIR customer, on the reseller's WhatsApp number and under their business
 * name — the platform replying into that thread would put our words in their
 * mouth. Answering happens in the reseller's own inbox, which is what
 * impersonation exists for.
 */
class TicketsController extends AdminController
{
    public function index(Request $request): Response
    {
        $this->authorise('tickets.view');

        $filters = TicketFilters::fromRequest($request);
        $query = TicketQuery::make();

        $page = $query->paginate($filters);
        $names = $query->tenantNames(
            collect($page->items())->pluck('tenant_id')->unique()->all(),
        );

        return Inertia::render('Admin/Tickets/Index', [
            'tickets' => [
                'data' => collect($page->items())
                    ->map(fn (Ticket $ticket) => TicketQuery::toRow(
                        $ticket,
                        $names[$ticket->tenant_id] ?? null,
                    ))
                    ->all(),
                'meta' => [
                    'currentPage' => $page->currentPage(),
                    'lastPage' => $page->lastPage(),
                    'perPage' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ],
            'filters' => $filters->toArray(),
            'isFiltered' => $filters->isFiltered(),
            'tabCounts' => Inertia::defer(fn () => $query->tabCounts($filters)),
            'busiest' => Inertia::defer(fn () => $query->busiest()),
        ]);
    }

    /**
     * One ticket's thread.
     *
     * JSON rather than a page: the list opens this in a panel over itself.
     */
    public function show(Ticket $ticket): JsonResponse
    {
        $this->authorise('tickets.view');

        $names = TicketQuery::make()->tenantNames([$ticket->tenant_id]);

        return response()->json([
            'ticket' => TicketQuery::toRow($ticket, $names[$ticket->tenant_id] ?? null),
            'messages' => $ticket->messages()
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->map(fn (TicketMessage $message) => [
                    'id' => $message->id,
                    'sender' => $message->sender,
                    'message' => $message->message,
                    'at' => $message->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }
}
