<?php

namespace App\Models;

use App\Services\Assistant\PlatformContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One thing the website assistant is allowed to say.
 *
 * The assistant answers from these rows and from the live price list, and from
 * nothing else. That is the whole design: a visitor told something confidently
 * wrong about what we sell arrives expecting it, and somebody then has to
 * either honour it or disappoint them.
 *
 * Not tenant-scoped — see the migration.
 */
class AssistantKnowledge extends Model
{
    use HasFactory;

    protected $table = 'assistant_knowledge';

    /**
     * The subjects a visitor can ask about, and the order the console lists
     * them in. Adding one here is enough — the admin form reads this.
     */
    public const TOPICS = [
        'order_bot' => 'Order Bot',
        'support_bot' => 'Support Bot',
        'ai_tickets' => 'AI Tickets',
        'ready_number' => 'Ready Number',
        'pricing' => 'Pricing',
        'getting_started' => 'Getting started',
        'whatsapp_setup' => 'WhatsApp setup',
        'payments' => 'Payments',
        'contact' => 'Contact & support',
    ];

    public const STATUSES = ['active', 'hidden'];

    /** Cache key for the prompt built from these rows. */
    public const CACHE_TAG = 'assistant.knowledge';

    protected $fillable = [
        'topic',
        'question',
        'question_sw',
        'answer',
        'answer_sw',
        'cta_label',
        'cta_url',
        'keywords',
        'status',
        'sort_order',
    ];

    protected static function booted(): void
    {
        // Editing an answer has to change what the assistant says now, not in
        // ten minutes. The console is the only writer, so this fires rarely.
        static::saved(fn () => static::forget());
        static::deleted(fn () => static::forget());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Every answer the assistant may use, ordered as the console arranged
     * them. Cached because it is read on every question asked and changes only
     * when somebody edits it.
     *
     * @return Collection<int, self>
     */
    public static function live(): Collection
    {
        return Cache::remember(
            self::CACHE_TAG,
            now()->addMinutes(30),
            fn () => static::active()->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    /**
     * Both caches these rows feed: the row list itself, and the prompt built
     * from it. Forgetting one without the other leaves the assistant quoting
     * an answer the console has already replaced.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_TAG);
        PlatformContext::forget();
    }

    /** The answer in the visitor's language, falling back to English. */
    public function answerIn(string $locale): string
    {
        if ($locale === 'sw' && filled($this->answer_sw)) {
            return (string) $this->answer_sw;
        }

        return (string) $this->answer;
    }

    /**
     * The question as this visitor would ask it — what a follow-up chip says.
     *
     * Falls back to English rather than hiding the suggestion: an untranslated
     * question is still a question worth offering, and a missing translation
     * should cost polish rather than a route through the conversation.
     */
    public function questionIn(string $locale): string
    {
        if ($locale === 'sw' && filled($this->question_sw)) {
            return (string) $this->question_sw;
        }

        return (string) $this->question;
    }

    /** @return array{label: string, url: string}|null */
    public function cta(): ?array
    {
        if (blank($this->cta_label) || blank($this->cta_url)) {
            return null;
        }

        return ['label' => (string) $this->cta_label, 'url' => (string) $this->cta_url];
    }

    /**
     * Everything this row can be matched on, lowercased: the question in both
     * languages, plus whatever synonyms were added for it.
     *
     * @return array<int, string>
     */
    public function searchTerms(): array
    {
        $terms = preg_split('/[,\n]+/', (string) $this->keywords) ?: [];

        return collect($terms)
            ->push($this->question, $this->question_sw)
            ->map(fn (?string $term) => mb_strtolower(trim((string) $term)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function topicLabel(): string
    {
        return self::TOPICS[$this->topic] ?? $this->topic;
    }
}
