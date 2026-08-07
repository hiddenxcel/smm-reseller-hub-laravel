<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;

/**
 * Turning tickets into the rows the screens render.
 *
 * Shared by both sides so a status badge means the same thing in the console as
 * it does on the reseller's screen, but the message shapes stay separate: the
 * console names the admin who wrote a reply, and the reseller's copy must not.
 */
class SupportTicketPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'category_label' => $ticket->categoryLabel(),
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'last_reply_by' => $ticket->last_reply_by,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'messages_count' => $ticket->messages_count,
        ];
    }

    /**
     * A row for the console queue: the same, plus whose account it came from
     * and how long it has been sitting.
     *
     * @return array<string, mixed>
     */
    public static function adminRow(SupportTicket $ticket): array
    {
        return [
            ...static::row($ticket),
            'tenant' => $ticket->tenant === null ? null : [
                'id' => $ticket->tenant->id,
                'business_name' => $ticket->tenant->business_name,
                'email' => $ticket->tenant->email,
            ],
            'awaiting_us' => $ticket->awaitingUs(),
            'waiting_hours' => $ticket->awaitingUs() && $ticket->last_reply_at !== null
                ? (int) $ticket->last_reply_at->diffInHours(now())
                : null,
            'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
        ];
    }

    /**
     * A message as the reseller sees it.
     *
     * Every admin reply is simply "Support": which member of staff answered is
     * the platform's business, not the reseller's, and naming them invites
     * resellers to ask for one by name.
     *
     * @return array<string, mixed>
     */
    public static function message(SupportTicketMessage $message): array
    {
        return [
            'id' => $message->id,
            'author' => $message->author,
            'author_label' => $message->author === 'admin' ? 'Support' : 'You',
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * A message as the console sees it: named, and marked if it is a note.
     *
     * @return array<string, mixed>
     */
    public static function adminMessage(SupportTicketMessage $message): array
    {
        return [
            'id' => $message->id,
            'author' => $message->author,
            'author_label' => $message->author === 'admin'
                ? ($message->admin?->displayName() ?? 'Support')
                : 'Reseller',
            'body' => $message->body,
            'internal' => $message->internal,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
