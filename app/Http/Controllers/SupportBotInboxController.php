<?php

namespace App\Http\Controllers;

use App\Models\BotCustomer;
use App\Models\BotMessage;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Support\SupportReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The support bot's inbox — this one can reply, unlike the order bot's.
 *
 * The difference is not that support conversations are safer to interrupt;
 * it is that here there is a defined moment when the bot stands down. A
 * customer who presses 5 ("Talk to a Human Agent") hands the conversation
 * over, and from then until staff hand it back the bot answers nothing. So a
 * reply typed here reaches a customer who is expecting a person.
 *
 * Staff can also reply to a conversation the bot still owns; doing so claims
 * it, for the same reason — two voices answering one customer is the failure
 * being designed out.
 *
 * The thread merges two sources. `bot_messages` is everything that crossed the
 * wire, bot chatter included; `ticket_messages` is the human conversation.
 * Both are shown, because a person picking up mid-conversation needs to see
 * what the bot already told the customer.
 */
class SupportBotInboxController extends Controller
{
    private const BOT = 'support';

    /** Conversations in the list. Older ones stay reachable through search. */
    private const CONVERSATION_LIMIT = 50;

    /** Messages loaded for one conversation. */
    private const THREAD_LIMIT = 200;

    public function index(Request $request): Response
    {
        $tenantId = (int) $request->user()->id;
        $phone = trim((string) $request->query('phone', ''));
        $search = trim((string) $request->query('q', ''));

        return Inertia::render('SupportBot/Inbox', [
            'conversations' => $this->conversations($tenantId, $search),
            'q' => $search,
            'phone' => $phone === '' ? null : $phone,
            'thread' => $phone === '' ? null : $this->thread($request, $tenantId, $phone),
            'canSend' => SupportReply::for($request->user())->supportNumber() !== null,
        ]);
    }

    /**
     * One row per customer, most recently active first.
     *
     * The subquery finds each customer's newest message id, then joins back for
     * its body — a plain GROUP BY would pair the largest id with some other
     * row's text, which is how these lists end up showing the wrong preview.
     */
    private function conversations(int $tenantId, string $search): array
    {
        $latest = BotMessage::withoutTenantScope()
            ->selectRaw('customer_phone, MAX(id) AS last_id')
            ->where('tenant_id', $tenantId)
            ->where('bot_type', self::BOT)
            ->groupBy('customer_phone');

        $rows = DB::query()
            ->fromSub($latest, 'latest')
            ->join('bot_messages as m', 'm.id', '=', 'latest.last_id')
            ->leftJoin('bot_customers as c', function ($join) use ($tenantId) {
                $join->on('c.phone', '=', 'latest.customer_phone')
                    ->where('c.tenant_id', '=', $tenantId);
            })
            ->when($search !== '', fn ($q) => $q->where(
                fn ($inner) => $inner->where('latest.customer_phone', 'like', "%{$search}%")
                    ->orWhere('c.name', 'ilike', "%{$search}%")
                    ->orWhere('m.message', 'ilike', "%{$search}%"),
            ))
            ->orderByDesc('m.id')
            ->limit(self::CONVERSATION_LIMIT)
            ->get([
                'latest.customer_phone',
                'm.message as last_message',
                'm.direction as last_direction',
                'm.created_at as last_at',
                'c.name',
                'c.blocked_at',
            ]);

        // Which of these are waiting on a person. Fetched in one query keyed by
        // phone rather than per row — the list is what makes a reseller notice
        // somebody is waiting, so it cannot cost fifty queries to draw.
        $handoffs = Ticket::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('handed_over_at')
            ->openish()
            ->pluck('customer_identifier')
            ->flip();

        return $rows->map(fn ($row) => [
            'phone' => $row->customer_phone,
            'name' => $row->name,
            'lastMessage' => $row->last_message,
            'lastDirection' => $row->last_direction,
            'lastAt' => $this->iso($row->last_at),
            'blocked' => $row->blocked_at !== null,
            'awaitingHuman' => $handoffs->has($row->customer_phone),
        ])->all();
    }

    /**
     * One conversation, oldest first — a chat reads downwards.
     *
     * Bot traffic and staff replies are interleaved by time, so the thread
     * reads as the customer experienced it. Staff messages are read from the
     * ticket rather than from `bot_messages` so their sender is not guessed
     * from the direction: the bot's own replies are outbound too.
     */
    private function thread(Request $request, int $tenantId, string $phone): array
    {
        $ticket = Ticket::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_identifier', $phone)
            ->openish()
            ->latest('id')
            ->first();

        $staffReplies = [];

        $messages = BotMessage::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', self::BOT)
            ->where('customer_phone', $phone)
            ->orderByDesc('id')
            ->limit(self::THREAD_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (BotMessage $message) => [
                'id' => "m{$message->id}",
                'sender' => $message->direction === 'in' ? 'customer' : 'bot',
                'message' => $message->message,
                'at' => $message->created_at?->toIso8601String(),
                'sortAt' => $message->created_at?->getTimestamp() ?? 0,
            ])
            ->values()
            ->all();

        if ($ticket !== null) {
            // Only staff rows: customer messages already arrived through
            // `bot_messages`, and including them here would double every line
            // the customer sent after the handoff.
            $staffReplies = TicketMessage::where('ticket_id', $ticket->id)
                ->where('sender', 'staff')
                ->orderBy('id')
                ->get()
                ->map(fn (TicketMessage $message) => [
                    'id' => "t{$message->id}",
                    'sender' => 'staff',
                    'message' => $message->message,
                    'at' => $message->created_at?->toIso8601String(),
                    'sortAt' => $message->created_at?->getTimestamp() ?? 0,
                ])
                ->all();
        }

        $merged = [...$messages, ...$staffReplies];
        usort($merged, fn (array $a, array $b) => $a['sortAt'] <=> $b['sortAt']);

        $customer = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->first();

        return [
            'phone' => $phone,
            'name' => $customer?->name,
            'blocked' => $customer?->blocked_at !== null,
            'balance' => $customer === null ? null : (float) $customer->balance,
            'ticketId' => $ticket?->id,
            'handedOver' => $ticket?->handed_over_at !== null,
            'withinWindow' => SupportReply::for($request->user())->withinWindow($phone),
            // `sortAt` did its job above; it is not part of the payload.
            'messages' => array_map(
                fn (array $row) => Arr::except($row, 'sortAt'),
                $merged,
            ),
        ];
    }

    /**
     * Send a reply. Creates the ticket if this conversation never had one —
     * staff answering someone the bot was still handling is a valid start.
     */
    public function reply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $tenantId = (int) $request->user()->id;

        $ticket = Ticket::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_identifier', $data['phone'])
            ->openish()
            ->latest('id')
            ->first()
            ?? Ticket::openHandoff($tenantId, $data['phone'], 'Answered from the inbox');

        $outcome = SupportReply::for($request->user())->send($ticket, $data['message']);

        return back()->with($outcome->failed ? 'error' : 'success', $outcome->message);
    }

    /**
     * Hand the conversation back to the bot.
     *
     * The customer is told, because from their side the person they were
     * talking to simply stops answering otherwise.
     */
    public function returnToBot(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $tenantId = (int) $request->user()->id;
        $ticket = Ticket::handoffFor($tenantId, $data['phone']);

        if ($ticket === null) {
            return back()->with('error', 'That conversation is already with the bot.');
        }

        $ticket->returnToBot();

        return back()->with('success', 'Returned to the bot. It will answer the next message.');
    }

    /** Raw joins hand back strings, not Carbon instances. */
    private function iso(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format(\DATE_ATOM)
            : (string) Carbon::parse($value)->toIso8601String();
    }
}
