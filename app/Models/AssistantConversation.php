<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One visitor's chat on the website.
 *
 * Identified by a token their browser holds for the length of the tab, which
 * is the most identity a marketing chat should ask for. It survives moving
 * from the landing page to /pricing — the thing that matters, since that walk
 * is exactly what the assistant is meant to cause — and it does not survive
 * closing the tab.
 */
class AssistantConversation extends Model
{
    use HasFactory;

    /**
     * How much of the conversation goes back to the model as context.
     *
     * Four turns is what the WhatsApp support bot uses, and for the same
     * reason: enough for "and how much is that one?" to resolve, short enough
     * that a long chat cannot quietly grow the cost of every later question.
     */
    public const HISTORY_TURNS = 4;

    protected $fillable = [
        'session_token',
        'page',
        'locale',
        'messages_count',
        'escalated_at',
        'lead_name',
        'lead_phone',
        'lead_message',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return ['escalated_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id');
    }

    /**
     * The conversation this token names, opening one if it is the first
     * message.
     *
     * An unknown token is treated as a new conversation rather than an error.
     * A visitor whose token we have lost — a wiped session, a restarted test
     * database — should get an answer, not a failure they cannot act on.
     */
    public static function forToken(?string $token, Request $request, ?string $page = null): self
    {
        $existing = filled($token) && Str::isUuid($token)
            ? static::where('session_token', $token)->first()
            : null;

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'session_token' => Str::uuid()->toString(),
            'page' => $page,
            'ip' => $request->ip(),
            // Truncated rather than validated: it is read by a human looking
            // at one odd conversation, never queried.
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);
    }

    /**
     * Earlier turns in the shape DeepSeek wants them.
     *
     * Oldest first, and only whole exchanges — handing the model a reply with
     * no question in front of it invites it to answer the wrong one.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function history(): array
    {
        return $this->messages()
            ->latest('id')
            ->limit(self::HISTORY_TURNS * 2)
            ->get()
            ->reverse()
            ->map(fn (AssistantMessage $message) => [
                'role' => $message->role,
                'content' => (string) $message->content,
            ])
            ->values()
            ->all();
    }

    public function record(string $role, string $content, ?string $answeredBy = null, ?int $knowledgeId = null): AssistantMessage
    {
        return $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'answered_by' => $answeredBy,
            'matched_knowledge_id' => $knowledgeId,
        ]);
    }

    public function hasEscalated(): bool
    {
        return $this->escalated_at !== null;
    }
}
