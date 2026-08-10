<?php

namespace App\Services\Admin;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Support\Facades\DB;

/**
 * What the website assistant has been asked, and how it went.
 *
 * The numbers here answer one question: is the assistant earning its place, or
 * is it a chat box that sends everybody to WhatsApp anyway? Three figures
 * settle it — how many were answered from written answers (free and exactly
 * right), how many the model handled, and how many nobody could answer.
 *
 * The last of those is not a failure report. It is a to-do list, written by
 * the people who wanted the answer.
 */
class AssistantInsights
{
    /** The reporting window everything here uses, in days. */
    private const WINDOW = 7;

    private const LIST_LIMIT = 12;

    /** @return array<string, int> */
    public static function stats(): array
    {
        $since = now()->subDays(self::WINDOW);

        $byOutcome = AssistantMessage::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('answered_by')
            ->groupBy('answered_by')
            ->select('answered_by', DB::raw('count(*) as total'))
            ->pluck('total', 'answered_by');

        return [
            'days' => self::WINDOW,
            'conversations' => AssistantConversation::where('created_at', '>=', $since)->count(),
            'today' => AssistantConversation::where('created_at', '>=', now()->startOfDay())->count(),
            'fromKnowledge' => (int) $byOutcome->get(AssistantMessage::KNOWLEDGE, 0),
            'fromAi' => (int) $byOutcome->get(AssistantMessage::AI, 0),
            'unanswered' => (int) $byOutcome->get(AssistantMessage::FALLBACK, 0),
            'escalated' => AssistantConversation::where('created_at', '>=', $since)
                ->whereNotNull('escalated_at')
                ->count(),
            'leads' => AssistantConversation::where('created_at', '>=', $since)
                ->whereNotNull('lead_phone')
                ->count(),
        ];
    }

    /**
     * What people ask most, and how each one was handled.
     *
     * Grouped on the lowercased text, so the same question typed three ways is
     * still three rows — deliberately. Two of those rows probably need
     * different keywords, and merging them would hide exactly that.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function topQuestions(): array
    {
        return self::questions(null);
    }

    /**
     * The questions the assistant could not answer.
     *
     * The most useful list on the screen. Each row is a knowledge entry
     * waiting to be written, in the visitor's own words.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unanswered(): array
    {
        return self::questions(AssistantMessage::FALLBACK);
    }

    /**
     * @param  string|null  $outcome  restrict to questions answered this way
     * @return array<int, array<string, mixed>>
     */
    private static function questions(?string $outcome): array
    {
        // A question is the visitor's turn; how it went is recorded on the
        // reply immediately after it.
        //
        // Immediately matters. Matching every later reply would count one
        // question once per answer that followed it, so a long conversation
        // would report its opening question five times and dominate the list.
        return AssistantMessage::query()
            ->from('assistant_messages as asked')
            ->join('assistant_messages as answer', 'answer.id', '=', DB::raw(
                '(select min(reply.id) from assistant_messages as reply'
                ." where reply.conversation_id = asked.conversation_id and reply.id > asked.id and reply.role = 'assistant')"
            ))
            ->where('asked.role', 'user')
            ->where('asked.created_at', '>=', now()->subDays(self::WINDOW))
            ->when($outcome !== null, fn ($query) => $query->where('answer.answered_by', $outcome))
            ->groupBy(DB::raw('lower(asked.content)'), 'answer.answered_by')
            ->select(
                DB::raw('lower(asked.content) as question'),
                'answer.answered_by',
                DB::raw('count(*) as asked_count'),
                DB::raw('max(asked.conversation_id) as conversation_id'),
            )
            ->orderByDesc('asked_count')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'question' => $row->question,
                'answeredBy' => $row->answered_by,
                'count' => (int) $row->asked_count,
                'conversationId' => (int) $row->conversation_id,
            ])
            ->all();
    }

    /**
     * People who asked to be called back.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function leads(): array
    {
        return AssistantConversation::whereNotNull('lead_phone')
            ->orderByDesc('escalated_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (AssistantConversation $conversation) => [
                'id' => $conversation->id,
                'name' => $conversation->lead_name,
                'phone' => $conversation->lead_phone,
                'message' => $conversation->lead_message,
                'page' => $conversation->page,
                'at' => $conversation->escalated_at?->diffForHumans(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public static function thread(AssistantConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'page' => $conversation->page,
            'locale' => $conversation->locale,
            'startedAt' => $conversation->created_at?->diffForHumans(),
            'lead' => $conversation->lead_phone === null ? null : [
                'name' => $conversation->lead_name,
                'phone' => $conversation->lead_phone,
                'message' => $conversation->lead_message,
            ],
            'messages' => $conversation->messages()
                ->orderBy('id')
                ->get()
                ->map(fn (AssistantMessage $message) => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'answeredBy' => $message->answered_by,
                    'at' => $message->created_at?->format('H:i'),
                ])
                ->all(),
        ];
    }
}
