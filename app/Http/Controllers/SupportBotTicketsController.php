<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Support\SupportReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tickets — the structured side of support, next to the inbox's free-form one.
 *
 * A ticket is opened either by the bot (category `ai`: a refill request, a
 * partial-completion report) or by the customer asking for a person (category
 * `human`). Both land here, because a reseller working through their backlog
 * wants one list, not two.
 *
 * Replying goes out over WhatsApp through the same path the inbox uses, so a
 * customer never has to check a portal to read an answer. If the send fails,
 * nothing is written to the thread — see SupportReply.
 */
class SupportBotTicketsController extends Controller
{
    private const STATUSES = ['open', 'pending', 'resolved', 'closed'];

    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        $tenantId = (int) $request->user()->id;

        $status = (string) $request->query('status', '');
        $category = (string) $request->query('category', '');
        $search = trim((string) $request->query('q', ''));

        $tickets = Ticket::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->when(in_array($status, self::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when(in_array($category, ['ai', 'human'], true), fn ($q) => $q->where('category', $category))
            ->when($search !== '', fn ($q) => $q->where(
                fn ($inner) => $inner->where('customer_identifier', 'like', "%{$search}%")
                    ->orWhere('subject', 'ilike', "%{$search}%")
                    ->orWhere('order_ref', 'like', "%{$search}%"),
            ))
            ->withCount('messages')
            // Handed-over tickets first: somebody is waiting on a person for
            // those, which is the only thing on this screen that is urgent.
            ->orderByRaw('handed_over_at IS NULL')
            ->latest('updated_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('SupportBot/Tickets', [
            'counts' => Ticket::statusCounts($tenantId),
            'filters' => ['status' => $status, 'category' => $category, 'q' => $search],
            'rows' => collect($tickets->items())->map($this->row(...))->all(),
            'page' => $tickets->currentPage(),
            'lastPage' => $tickets->lastPage(),
            'total' => $tickets->total(),
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $this->authorizeTicket($request, $ticket);

        return Inertia::render('SupportBot/Ticket', [
            'ticket' => [
                ...$this->row($ticket),
                'withinWindow' => blank($ticket->customer_identifier)
                    ? false
                    : SupportReply::for($request->user())->withinWindow($ticket->customer_identifier),
                'canSend' => SupportReply::for($request->user())->supportNumber() !== null,
                'messages' => $ticket->messages()
                    ->orderBy('id')
                    ->get()
                    ->map(fn (TicketMessage $message) => [
                        'id' => $message->id,
                        'sender' => $message->sender,
                        'message' => $message->message,
                        'at' => $message->created_at?->toIso8601String(),
                    ])->all(),
            ],
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request, $ticket);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $outcome = SupportReply::for($request->user())->send($ticket, $data['message']);

        return back()->with($outcome->failed ? 'error' : 'success', $outcome->message);
    }

    /**
     * Change status, priority, or hand the conversation back to the bot.
     *
     * Resolving does NOT hand back on its own. A reseller often answers the
     * question and keeps the conversation while the customer reads it, and
     * just as often closes a ticket the bot was never holding — so the two
     * are separate switches rather than one inferred from the other.
     */
    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request, $ticket);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'handedOver' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('status', $data)) {
            $ticket->status = $data['status'];
        }

        if (array_key_exists('priority', $data)) {
            $ticket->priority = $data['priority'];
        }

        if (array_key_exists('handedOver', $data)) {
            $ticket->handed_over_at = $data['handedOver'] ? ($ticket->handed_over_at ?? now()) : null;
        }

        $ticket->save();

        return back()->with('success', 'Ticket updated.');
    }

    /** @return array<string, mixed> */
    private function row(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'customer' => $ticket->customer_identifier,
            'category' => $ticket->category,
            'subcategory' => $ticket->subcategory,
            'orderRef' => $ticket->order_ref,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'handedOver' => $ticket->handed_over_at !== null,
            'messages' => $ticket->messages_count ?? $ticket->messages()->count(),
            'updatedAt' => $ticket->updated_at?->toIso8601String(),
            'createdAt' => $ticket->created_at?->toIso8601String(),
        ];
    }

    /**
     * Route-model binding resolves by id alone, so without this a reseller
     * could read another tenant's ticket by typing its number in the URL.
     */
    private function authorizeTicket(Request $request, Ticket $ticket): void
    {
        abort_unless((int) $ticket->tenant_id === (int) $request->user()->id, 404);
    }
}
