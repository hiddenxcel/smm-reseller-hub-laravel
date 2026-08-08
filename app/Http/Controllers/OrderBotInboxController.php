<?php

namespace App\Http\Controllers;

use App\Models\BotCustomer;
use App\Models\BotMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The order bot's inbox — read-only, on purpose.
 *
 * The order bot sells by itself: a reply typed in here would arrive in the
 * middle of a conversation the bot is already driving, and the customer would
 * be answering two people at once. So this shows what was said and nothing
 * more. Replying belongs to the support bot, which is built to hold a
 * conversation, and lives on its own screen.
 *
 * `bot_messages` is the only table carrying a bot column, which is why the
 * inbox splits cleanly per bot when orders and customers cannot.
 */
class OrderBotInboxController extends Controller
{
    private const BOT = 'order';

    /** Conversations in the list. Older ones stay reachable through search. */
    private const CONVERSATION_LIMIT = 50;

    /** Messages loaded for one conversation. */
    private const THREAD_LIMIT = 200;

    public function __invoke(Request $request): Response
    {
        $tenantId = (int) $request->user()->id;
        $phone = trim((string) $request->query('phone', ''));
        $search = trim((string) $request->query('q', ''));

        return Inertia::render('OrderBot/Inbox', [
            'conversations' => $this->conversations($tenantId, $search),
            'q' => $search,
            'phone' => $phone === '' ? null : $phone,
            // Only fetched when a conversation is open — the list alone is the
            // common case, and the thread query is the expensive one.
            'thread' => $phone === '' ? null : $this->thread($tenantId, $phone),
        ]);
    }

    /**
     * One row per customer, most recently active first.
     *
     * The subquery finds each customer's newest message id, then joins back for
     * its body — a plain GROUP BY would return the largest id alongside some
     * other row's text, which is the classic way this list ends up showing the
     * wrong preview.
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

        return $rows->map(fn ($row) => [
            'phone' => $row->customer_phone,
            'name' => $row->name,
            'lastMessage' => $row->last_message,
            'lastDirection' => $row->last_direction,
            'lastAt' => $this->iso($row->last_at),
            'blocked' => $row->blocked_at !== null,
        ])->all();
    }

    /**
     * One conversation, oldest first — a chat reads downwards.
     *
     * Capped at the most recent messages: a customer with thousands of them
     * would otherwise stall the page, and it is the recent ones being read.
     */
    private function thread(int $tenantId, string $phone): array
    {
        $messages = BotMessage::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('bot_type', self::BOT)
            ->where('customer_phone', $phone)
            ->orderByDesc('id')
            ->limit(self::THREAD_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $customer = BotCustomer::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->first();

        return [
            'phone' => $phone,
            'name' => $customer?->name,
            'blocked' => $customer?->blocked_at !== null,
            'balance' => $customer === null ? null : (float) $customer->balance,
            'messages' => $messages->map(fn (BotMessage $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'message' => $message->message,
                'at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ];
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
