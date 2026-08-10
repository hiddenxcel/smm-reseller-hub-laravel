<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in a website conversation.
 *
 * `answered_by` is the column the console is built on. Three answers cost
 * three different things and mean three different things:
 *
 *   - knowledge: matched a written answer. Free, instant, and exactly right.
 *   - ai:        DeepSeek wrote it from the prompt. Costs money, usually good.
 *   - fallback:  nobody could answer. A knowledge row waiting to be written.
 */
class AssistantMessage extends Model
{
    use HasFactory;

    /** Only created_at: a chat turn is never edited. */
    public const UPDATED_AT = null;

    public const KNOWLEDGE = 'knowledge';

    public const AI = 'ai';

    public const FALLBACK = 'fallback';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'answered_by',
        'matched_knowledge_id',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function knowledge(): BelongsTo
    {
        return $this->belongsTo(AssistantKnowledge::class, 'matched_knowledge_id');
    }
}
