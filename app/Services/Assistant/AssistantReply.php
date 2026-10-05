<?php

namespace App\Services\Assistant;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledge;
use App\Models\AssistantMessage;
use App\Services\Ai\DeepSeekClient;

/**
 * Answering one question from a website visitor.
 *
 * Three ways an answer can come back, tried in order:
 *
 *   1. A written answer, matched on the words they used. Free and instant,
 *      and it is exactly what we meant to say. Every quick-action button in
 *      the widget lands here, which is why they feel immediate.
 *   2. DeepSeek, working from a prompt containing those same written answers
 *      and the live price list. Costs money; used for questions nobody wrote
 *      an answer to yet.
 *   3. A written fallback offering a human. Used when the model is unreachable
 *      or refuses.
 *
 * The order matters for cost and for trust. Anything we have already decided
 * how to answer is answered that way every time, identically, rather than
 * re-improvised on each visitor.
 */
class AssistantReply
{
    /**
     * How close a question must be to a written one to use it directly.
     *
     * Set high on purpose. Answering the wrong written answer is worse than
     * passing the question to the model, which has all the same answers in
     * front of it and can pick between them with actual understanding.
     */
    private const MATCH_THRESHOLD = 0.82;

    /**
     * The fewest words a search term may have and still decide a match alone.
     *
     * Without this, a one-word keyword matches at full confidence every time
     * that word appears anywhere: "api" made "How much does the OpenAI API
     * cost?" match the answer about connecting SMM panels — the exact
     * off-topic question this assistant is supposed to refuse. Short terms
     * still count towards a longer phrase; they just cannot carry one.
     */
    private const MIN_TERM_WORDS = 2;

    /** Follow-up questions offered under an answer. */
    private const SUGGESTIONS = 3;

    /** Buttons under one answer. Two is a next step; three is a menu. */
    private const MAX_CTAS = 2;

    /** Longest answer the model may write, in tokens. */
    private const AI_MAX_TOKENS = 700;

    /**
     * Answer, record both turns, and return everything the widget renders.
     *
     * @return array{
     *     reply: string,
     *     cta: array{label: string, url: string}|null,
     *     suggestions: array<int, string>,
     *     answered_by: string,
     * }
     */
    public function answer(
        AssistantConversation $conversation,
        string $question,
        ?string $page = null,
        ?string $chosen = null,
    ): array {
        // The visitor's own choice beats any guess; failing that, a sentence
        // plainly in some other language; failing that, the English/Kiswahili
        // detection that was here first, untouched.
        $language = VisitorLanguage::resolve(
            $question,
            $conversation->locale,
            VisitorLanguage::normalize($chosen),
        );

        // Written answers exist in English and Kiswahili only. Everyone else is
        // answered by the model, in their language, and the English base is
        // what that prompt is built on.
        $other = ! in_array($language, AssistantLanguage::SUPPORTED, true);
        $locale = $other ? 'en' : $language;

        // Read before the question is recorded, or the model is handed the
        // question twice — once as history and once as the question.
        $history = $conversation->history();

        $conversation->record('user', $question);

        // "ongea kiswahili" is an instruction, not a question. It has already
        // been obeyed — the locale above is the answer to it — so the reply is
        // an acknowledgement in the new language rather than a search for
        // something it could never have found.
        $switched = ! $other && AssistantLanguage::requested($question) === $locale;

        $courtesy = $switched || $other ? null : AssistantLanguage::courtesy($question);
        $matched = $courtesy === null && ! $switched && ! $other ? $this->match($question) : null;

        $result = match (true) {
            $other => $this->fromAi($question, $history, $locale, $page, $language),
            $switched => $this->fromCourtesy('switched', $locale),
            // "asante", "sawa", "hi" — answered in kind. Sent to the model
            // these cost a paid call to improvise a "you're welcome"; matched
            // against the knowledge base they find nothing and come back as "I
            // do not have that in the knowledge base", which is a strange
            // thing to tell somebody who just thanked you.
            $courtesy !== null => $this->fromCourtesy($courtesy, $locale),
            $matched !== null => $this->fromKnowledge($matched, $locale),
            default => $this->fromAi($question, $history, $locale, $page),
        };

        $conversation->record(
            'assistant',
            $result['reply'],
            $result['answered_by'],
            $matched?->id,
        );

        // Counted in turns rather than rows, since that is what the console
        // and the escalation prompt both mean by "how long has this gone on".
        $conversation->increment('messages_count');
        $conversation->forceFill(['locale' => $language])->save();

        return [
            ...$result,
            // The written follow-ups are English or Kiswahili. In any other
            // language the widget shows its own, translated, instead of
            // chips that switch the visitor back.
            'suggestions' => $other ? [] : $this->suggestions($matched, $locale),
            // Returned rather than read back off the conversation, so the
            // caller cannot accidentally report the language of the turn
            // before this one.
            'locale' => $language,
            'rtl' => VisitorLanguage::isRtl($language),
        ];
    }

    /**
     * The written answer for this question, if one clearly fits.
     *
     * Matched on whole words rather than substrings: "pay" inside "payment"
     * is a real match, but "api" inside "rapid" is not, and the second kind
     * is how a keyword search starts answering confidently wrong questions.
     */
    private function match(string $question): ?AssistantKnowledge
    {
        $asked = AssistantLanguage::words($question);

        if ($asked === []) {
            return null;
        }

        // What they asked about, with the question-asking words removed.
        // Everything below is decided on these.
        $subject = AssistantLanguage::subject($asked);

        $best = null;
        $bestScore = 0.0;
        $bestLength = 0;

        foreach (AssistantKnowledge::live() as $entry) {
            foreach ($entry->searchTerms() as $term) {
                $words = AssistantLanguage::words($term);

                if (count($words) < self::MIN_TERM_WORDS) {
                    continue;
                }

                $termSubject = AssistantLanguage::subject($words);

                // A term with no subject of its own — "how much does it cost"
                // — has nothing to compare against, so it is matched on its
                // full wording instead. That is strict on purpose: the only
                // way to reach it is to ask it almost exactly, rather than by
                // sharing a few question-words with it.
                $score = $termSubject === []
                    ? $this->overlap($asked, $words)
                    : $this->overlap($subject, $termSubject);

                // Ties go to the term that said more. "what is order bot"
                // and "how does order bot work" share three words; the
                // question matching all five of one of them means that one,
                // and without this the first row written would win both.
                //
                // Both trackers are local: this service is resolved from the
                // container and, under a persistent worker, answers more than
                // one question. Held on the instance, the tie-break would
                // compare against whatever the previous visitor asked.
                if ($score > $bestScore || ($score === $bestScore && $score > 0 && count($words) > $bestLength)) {
                    $bestScore = $score;
                    $best = $entry;
                    $bestLength = count($words);
                }
            }
        }

        return $bestScore >= self::MATCH_THRESHOLD ? $best : null;
    }

    /**
     * How well a written question matches what the visitor asked about.
     *
     * Both sides are stripped to their subject first, which is what makes this
     * safe. Compared whole, "How much does the OpenAI API cost?" and "How much
     * does it cost?" share four words out of five and match — and the
     * assistant answers a question about somebody else's product with our
     * prices, which is the single worst thing it could do.
     *
     * Stripped, the first is about `openai api` and the second about nothing
     * in particular, and the mismatch is obvious. A subject word the visitor
     * used that we do not recognise is treated as evidence against, not as
     * padding to be ignored.
     *
     * @param  array<int, string>  $asked  the visitor's subject words
     * @param  array<int, string>  $term  the written question's subject words
     */
    private function overlap(array $asked, array $term): float
    {
        // A term that is nothing but question-words — "how much does it cost"
        // — is about nothing in particular, and two sentences that are both
        // about nothing are not the same question. "hello" reduces to the same
        // emptiness and must not land on the price answer. Only real subjects
        // can match, so such a term is reachable through its keywords instead.
        if ($term === [] || $asked === []) {
            return 0.0;
        }

        $hits = count(array_intersect($term, $asked));

        if ($hits === 0) {
            return 0.0;
        }

        $coverage = $hits / count($term);

        // And how much of what they asked about we recognised.
        //
        // Nothing is forgiven here. A subject word is what a question is
        // about, and one we do not know is usually the whole point of it:
        // "Google Ads cost per month" is our price answer plus two words, and
        // those two words are the entire question. Padding is already gone —
        // it was stripped as question-words before either side got here — so
        // anything left that we cannot place counts against the match.
        $recognised = $hits / count($asked);

        return $coverage * $recognised;
    }

    /**
     * A pleasantry, answered in kind.
     *
     * Booked as `knowledge` rather than `fallback`, and that matters more than
     * it looks: the console's most useful list is the questions nobody could
     * answer, and it is only useful while everything in it is a question. Fill
     * it with "thanks" and "ok" and it stops being a to-do list.
     *
     * @return array{reply: string, cta: array{label: string, url: string}|null, answered_by: string}
     */
    private function fromCourtesy(string $kind, string $locale): array
    {
        return [
            'reply' => AssistantLanguage::courtesyReply($kind, $locale),
            'cta' => null,
            'ctas' => [],
            'answered_by' => AssistantMessage::KNOWLEDGE,
        ];
    }

    /** @return array{reply: string, cta: array{label: string, url: string}|null, answered_by: string} */
    private function fromKnowledge(AssistantKnowledge $entry, string $locale): array
    {
        $cta = $this->safeCta($entry->cta());

        return [
            'reply' => $entry->answerIn($locale),
            'cta' => $cta,
            'ctas' => $cta === null ? [] : [$cta],
            'answered_by' => AssistantMessage::KNOWLEDGE,
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, cta: array{label: string, url: string}|null, answered_by: string}
     */
    private function fromAi(string $question, array $history, string $locale, ?string $page, ?string $language = null): array
    {
        $key = (string) AssistantKey::get();

        $answer = $key === ''
            ? null
            : (new DeepSeekClient($key))->ask(
                system: PlatformContext::for($locale, $page, $language),
                question: $question,
                history: $history,
                // Room for a short walkthrough. The reseller-facing bot is
                // held to 400 because its customers are on WhatsApp; a visitor
                // asking how setup works is owed more than a sentence.
                maxTokens: self::AI_MAX_TOKENS,
            );

        if ($answer === null) {
            return [
                'reply' => $language === null
                    ? AssistantLanguage::fallback($locale)
                    : VisitorLanguage::fallback($language),
                'cta' => null,
                'ctas' => [],
                'answered_by' => AssistantMessage::FALLBACK,
            ];
        }

        [$reply, $ctas] = $this->splitCtas($answer);

        $safe = array_values(array_filter(array_map(fn (array $cta) => $this->safeCta($cta), $ctas)));

        return [
            'reply' => $reply,
            // The first, under its old name, for anything still reading it.
            'cta' => $safe[0] ?? null,
            'ctas' => $safe,
            'answered_by' => AssistantMessage::AI,
        ];
    }

    /**
     * Pull every [[CTA:Label|/path]] marker out of an answer, at most two.
     *
     * More would be the model offering a menu instead of a next step, and the
     * rule it is given says two. A third is dropped rather than shown.
     *
     * @return array{0: string, 1: array<int, array{label: string, url: string}>}
     */
    private function splitCtas(string $answer): array
    {
        preg_match_all('/\[\[CTA:\s*([^|\]]+)\|\s*([^\]]+)\]\]/u', $answer, $found, PREG_SET_ORDER);

        $reply = $answer;
        $ctas = [];

        foreach ($found as $marker) {
            $reply = str_replace($marker[0], '', $reply);

            if (count($ctas) < self::MAX_CTAS) {
                $ctas[] = ['label' => trim($marker[1]), 'url' => trim($marker[2])];
            }
        }

        return [trim(preg_replace("/\n{3,}/", "\n\n", $reply) ?? $reply), $ctas];
    }

    /**
     * Pull a [[CTA:Label|/path]] marker out of an answer.
     *
     * The model writes it as text because that is all it can write; the widget
     * renders a button. Keeping the marker out of the visible reply matters —
     * a visitor who sees the raw syntax is looking at our plumbing.
     *
     * @return array{0: string, 1: array{label: string, url: string}|null}
     */
    private function splitCta(string $answer): array
    {
        if (! preg_match('/\[\[CTA:\s*([^|\]]+)\|\s*([^\]]+)\]\]/u', $answer, $found)) {
            return [trim($answer), null];
        }

        $reply = trim(str_replace($found[0], '', $answer));

        return [$reply, [
            'label' => trim($found[1]),
            'url' => trim($found[2]),
        ]];
    }

    /**
     * A CTA is only allowed to point somewhere that exists.
     *
     * The model is told the list and mostly obeys it; "mostly" is not a
     * standard worth shipping when the failure is a visitor landing on a 404
     * at the exact moment they decided to buy. The fragment is kept — anchors
     * are ours, and a path that is otherwise valid does not become invalid
     * because it points partway down the page.
     *
     * @param  array{label: string, url: string}|null  $cta
     * @return array{label: string, url: string}|null
     */
    private function safeCta(?array $cta): ?array
    {
        if ($cta === null || $cta['label'] === '' || $cta['url'] === '') {
            return null;
        }

        $path = '/'.ltrim(strtok($cta['url'], '#') ?: '/', '/');
        $path = $path === '//' ? '/' : rtrim($path, '/');

        if (! PlatformContext::allowsPath($path === '' ? '/' : $path)) {
            return null;
        }

        return ['label' => $cta['label'], 'url' => $cta['url']];
    }

    /**
     * What to offer asking next.
     *
     * From the same topic when we matched one, since somebody who just read
     * what Order Bot is usually wants to know how it works or what it costs.
     * Otherwise the opening questions, which is the useful thing to show
     * someone whose question we could not place.
     *
     * A topic with nothing left to ask falls back to the openers rather than
     * showing nothing — an empty row where chips were is a dead end.
     *
     * @return array<int, string>
     */
    private function suggestions(?AssistantKnowledge $matched, string $locale): array
    {
        $entries = AssistantKnowledge::live();

        $pool = $matched !== null
            ? $entries->where('topic', $matched->topic)->where('id', '!=', $matched->id)
            : $entries;

        if ($pool->isEmpty()) {
            $pool = $entries->where('id', '!=', $matched?->id);
        }

        return $pool
            ->take(self::SUGGESTIONS)
            ->map(fn (AssistantKnowledge $entry) => $entry->questionIn($locale))
            ->values()
            ->all();
    }
}
