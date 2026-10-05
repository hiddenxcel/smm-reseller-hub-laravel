<?php

namespace App\Services\Assistant;

use App\Services\Bots\BotLang;

/**
 * Working out what language a visitor wrote in, and normalising their words
 * for matching.
 *
 * The website is in English but a good share of the people reading it write
 * in Kiswahili, and being answered in the language you asked in is most of
 * what makes a chat feel like a person rather than a form.
 *
 * The detection here is a hint, not a verdict: it decides which written answer
 * to serve and which fallback to show, and it goes into the prompt as an
 * observation. The model is separately told to match whatever the visitor
 * actually writes, so a sentence this misreads still comes back in the right
 * language.
 */
class AssistantLanguage
{
    /** English and Kiswahili only — the two the website is read in. */
    public const SUPPORTED = ['en', 'sw'];

    /**
     * Kiswahili words that settle it on their own.
     *
     * These have no English homograph and appear in no English sentence, so
     * one of them is not evidence — it is the answer. "Order Bot ni nini?" is
     * three English words and one of these, and it is plainly a Kiswahili
     * question; weighing markers against sentence length calls it English.
     */
    private const DECISIVE = [
        'nini', 'vipi', 'ngapi', 'lini', 'kwanini', 'ninawezaje', 'inafanyaje',
        'inawezekana', 'kulipia', 'ninaunganishaje', 'wananilipaje', 'unachukua',
    ];

    /**
     * Kiswahili words common enough to count, and uncommon enough in English
     * not to fire on an English sentence.
     *
     * Weaker than the list above — several of these are short enough to appear
     * in an English sentence quoting a product name — so they are counted
     * rather than trusted individually.
     */
    private const MARKERS = [
        'kiasi', 'naweza', 'ninaweza', 'unaweza', 'nataka', 'nahitaji', 'bei',
        'gharama', 'namba', 'lipa', 'malipo', 'huduma', 'msaada', 'wasiliana',
        'tumia', 'kutumia', 'kuanza', 'inafanya', 'yangu', 'yako', 'zangu',
        'zako', 'kwa', 'ya', 'wa', 'za', 'na', 'ni', 'hii', 'hiyo', 'sasa',
        'pia', 'lakini', 'ndiyo', 'hapana', 'sina', 'nina', 'mimi', 'wewe',
        'gani', 'wapi', 'tayari', 'mna',
    ];

    /**
     * How many of the softer markers make a sentence Kiswahili.
     *
     * Two rather than one: words like "na" and "ya" appear in English
     * sentences quoting a Kiswahili product name, and one of them alone is not
     * evidence of anything.
     */
    private const THRESHOLD = 2;

    /**
     * Words that ask rather than name — in both languages, plus the filler
     * people open a chat with.
     *
     * Stripping these is what lets a written question be matched on what it is
     * about. "How much does it cost?" and "How much does the OpenAI API cost?"
     * are four-fifths identical as sentences and completely different as
     * questions; the difference lives entirely in the words left after this.
     */
    private const QUESTION_WORDS = [
        // English
        'how', 'what', 'when', 'where', 'which', 'who', 'why', 'is', 'are',
        'was', 'do', 'does', 'did', 'can', 'could', 'will', 'would', 'should',
        'the', 'and', 'for', 'you', 'your', 'yours', 'it', 'its', 'this',
        'that', 'there', 'here', 'about', 'with', 'from', 'into', 'have',
        'has', 'get', 'much', 'many', 'some', 'any', 'all', 'not', 'but',
        'hi', 'hey', 'hello', 'please', 'thanks', 'thank', 'ok', 'okay',
        'need', 'want', 'like', 'tell', 'know', 'exactly', 'really', 'just',
        // Sentence-openers people type without meaning anything by them. Left
        // in, "thanks, so what is Order Bot?" fails to match "what is order
        // bot" over the one word `so`.
        'so', 'well', 'anyway', 'also', 'now', 'then', 'actually', 'sorry',
        // Kiswahili
        'nini', 'vipi', 'ngapi', 'gani', 'wapi', 'lini', 'nani', 'kwa', 'ya',
        'wa', 'za', 'la', 'na', 'ni', 'hii', 'hiyo', 'hizo', 'sasa', 'pia',
        'lakini', 'ndiyo', 'hapana', 'yangu', 'yako', 'zangu', 'zako',
        'naweza', 'ninaweza', 'ninawezaje', 'unaweza', 'nataka', 'nahitaji',
        'mimi', 'wewe', 'kama', 'tu', 'au', 'je', 'sana', 'kidogo',
    ];

    /**
     * A plain request to be spoken to in one language or the other.
     *
     * "ongea kiswahili" is four words with one marker in it, so detection
     * alone reads it as English and answers in English — which is a bot
     * ignoring a direct instruction, and the rudest thing this widget could
     * do. Asked outright, the answer is the language, not a guess at it.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUESTS = [
        'sw' => ['kiswahili', 'swahili', 'kiswaili'],
        'en' => ['english', 'kiingereza'],
    ];

    /**
     * The language this whole conversation is in.
     *
     * Set by the first thing the visitor says and kept for the rest of it.
     * Detecting per question looks reasonable and is wrong: half of what
     * people type is short — "sawa", "ok", "asante", a bare link — and none of
     * it carries enough to detect. A conversation opened in Kiswahili would
     * flip to English on the second turn and back on the third.
     *
     * Two things override the established language: asking outright, and
     * writing a full sentence unmistakably in the other one.
     */
    public static function forConversation(string $text, ?string $current): string
    {
        $requested = self::requested($text);

        if ($requested !== null) {
            return $requested;
        }

        $established = in_array($current, self::SUPPORTED, true) ? $current : null;

        if ($established === null) {
            // A bare "asante" opening the conversation is not a sentence to
            // detect, but it is unmistakably Kiswahili, and answering it in
            // English teaches the visitor on their very first turn that this
            // thing does not speak their language.
            return self::courtesyLocale($text) ?? self::detect($text);
        }

        // Switching away from an established language takes more than a
        // stray word: the visitor has to write something that could not be
        // the language we are already in.
        return self::switchesTo($text, $established) ?? $established;
    }

    /**
     * Verb stems that turn a language's name into a request for it.
     *
     * Found anywhere inside a word rather than at its start, because
     * Kiswahili conjugates on the front as well as the back: ongea, uongee,
     * tuongee, niongeleshe and unaongea are one verb wearing five subjects.
     * Listing the forms is a list that is always missing one; the stem is the
     * part that does not move.
     */
    private const ASKING = [
        'ongea', 'ongee', 'ongel', 'sema', 'seme', 'jibu', 'tumia', 'andik',
        'zungumz', 'speak', 'reply', 'answer', 'talk', 'writ', 'switch',
        'use', 'change',
    ];

    /**
     * Which language they asked to be answered in, if they asked at all.
     *
     * Naming a language is not asking for it. "My customers speak Kiswahili
     * and English" describes their shop, and switching the conversation on
     * the strength of it would be the bot mishearing a fact as an
     * instruction — so a sentence naming both languages is never a request
     * for either.
     *
     * Public because the caller needs to tell an instruction from a question:
     * "ongea kiswahili" has already been carried out by the time the reply is
     * written, and searching for an answer to it finds nothing — which is how
     * a request obeyed perfectly ends up answered with "I do not have that in
     * the knowledge base".
     */
    public static function requested(string $text): ?string
    {
        $words = self::words($text);

        $named = [];

        foreach (self::REQUESTS as $locale => $names) {
            if (array_intersect($words, $names) !== []) {
                $named[] = $locale;
            }
        }

        if (count($named) !== 1) {
            return null;
        }

        foreach ($words as $word) {
            foreach (self::ASKING as $stem) {
                if (str_contains($word, $stem)) {
                    return $named[0];
                }
            }
        }

        return null;
    }

    /**
     * Whether this sentence is clearly in the other language.
     *
     * Deliberately harder than opening in one: the bar for changing an
     * established language mid-conversation is a whole sentence, not a
     * borrowed word. "Nataka Order Bot" is Kiswahili with an English product
     * name in it, and must not read as a switch to English.
     */
    private static function switchesTo(string $text, string $established): ?string
    {
        $detected = self::detect($text);

        if ($detected === $established) {
            return null;
        }

        // Switching into Kiswahili is trusted, because the markers that say so
        // do not appear in English. Switching out of it is not: an English
        // reading is what a sentence gets by default, including every sentence
        // too short to tell.
        if ($detected === 'sw') {
            return 'sw';
        }

        // A link, an email or a handle is not a sentence in any language, but
        // it splits into several English-looking words — a bare
        // "instagram.com/mystore" would otherwise end a Kiswahili
        // conversation, and pasting one is exactly what people do here.
        $prose = preg_replace('#\S*[./@]\S*#u', ' ', $text) ?? $text;

        return count(self::subject(self::words($prose))) >= 3 ? 'en' : null;
    }

    public static function detect(string $text): string
    {
        $words = self::words($text);

        if ($words === []) {
            return BotLang::DEFAULT;
        }

        if (array_intersect($words, self::DECISIVE) !== []) {
            return 'sw';
        }

        $hits = count(array_intersect($words, self::MARKERS));

        // A short question gets a lower bar: "bei ngapi?" is unambiguous, and
        // requiring two markers out of two words means requiring all of them.
        $needed = count($words) <= 3 ? 1 : self::THRESHOLD;

        return $hits >= $needed ? 'sw' : BotLang::DEFAULT;
    }

    /**
     * A sentence as comparable words: lowercased, punctuation dropped, and
     * everything shorter than two characters removed.
     *
     * Used on both sides of a knowledge match, so the visitor's phrasing and
     * ours are reduced the same way before they are compared.
     *
     * @return array<int, string>
     */
    public static function words(string $text): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text)) ?? '';

        return collect(preg_split('/\s+/', trim($clean)) ?: [])
            ->filter(fn (string $word) => mb_strlen($word) > 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * What a sentence is actually about: its words, minus the ones used to
     * ask with.
     *
     * Kept as a separate step from words() because both sides of a match need
     * the same treatment, and because the words removed here are still needed
     * for language detection — "ninawezaje" says nothing about the subject and
     * everything about the language.
     *
     * @param  array<int, string>  $words
     * @return array<int, string>
     */
    public static function subject(array $words): array
    {
        return array_values(array_diff($words, self::QUESTION_WORDS));
    }

    /**
     * Things people say that are not questions.
     *
     * Passing these to the model wastes a paid call to have it improvise a
     * "you're welcome"; matching them against the knowledge base finds nothing
     * and answers "I do not have that in the knowledge base", which is an
     * absurd reply to somebody saying thank you — and it books the turn as a
     * failure in the console, so the one number that should mean "write an
     * answer for this" fills up with pleasantries.
     *
     * @var array<string, array<int, string>>
     */
    private const COURTESIES = [
        'thanks' => [
            'asante', 'ahsante', 'asanti', 'shukrani', 'nashukuru',
            'thanks', 'thankyou', 'thx', 'ta',
        ],
        'ack' => [
            'sawa', 'poa', 'nzuri', 'safi', 'haya', 'vizuri', 'nimeelewa',
            'ok', 'okay', 'okey', 'cool', 'nice', 'great', 'alright', 'fine',
            'got', 'noted', 'perfect',
        ],
        'greeting' => [
            'hi', 'hello', 'hey', 'habari', 'mambo', 'salama', 'vipi', 'niaje',
            'shikamoo', 'hujambo', 'morning', 'afternoon', 'evening',
        ],
        'bye' => [
            'bye', 'goodbye', 'kwaheri', 'baadaye', 'tutaonana', 'later',
        ],
    ];

    /**
     * The kind of pleasantry this is, if that is all it is.
     *
     * Only when the whole message is one — "thanks, and what about refills?"
     * is a question wearing a thank-you, and must be answered as a question.
     */
    public static function courtesy(string $text): ?string
    {
        $words = self::words($text);

        if ($words === [] || count($words) > 3) {
            return null;
        }

        $matched = null;

        foreach ($words as $word) {
            $kind = self::kindOf($word);

            if ($kind === null) {
                return null;
            }

            // "thanks" alone and "ok thanks" are both thanks; the warmer word
            // wins so the reply matches what they actually said.
            $matched ??= $kind;
            $matched = $kind === 'thanks' ? 'thanks' : $matched;
        }

        return $matched;
    }

    /**
     * The Kiswahili courtesies, so a greeting can set the language it is in.
     *
     * "asante" opening a conversation carries no marker word and would
     * otherwise be read as English, and answered "any time" — which teaches
     * the visitor, on their very first turn, that this thing does not speak
     * their language.
     *
     * @var array<int, string>
     */
    private const SW_COURTESIES = [
        'asante', 'ahsante', 'asanti', 'shukrani', 'nashukuru', 'sawa', 'poa',
        'nzuri', 'safi', 'haya', 'vizuri', 'nimeelewa', 'habari', 'mambo',
        'salama', 'niaje', 'shikamoo', 'hujambo', 'kwaheri', 'baadaye',
        'tutaonana', 'sana',
    ];

    /** Whether a bare pleasantry is itself evidence of a language. */
    public static function courtesyLocale(string $text): ?string
    {
        return array_intersect(self::words($text), self::SW_COURTESIES) !== [] ? 'sw' : null;
    }

    private static function kindOf(string $word): ?string
    {
        foreach (self::COURTESIES as $kind => $words) {
            if (in_array($word, $words, true)) {
                return $kind;
            }
        }

        // Filler that carries no meaning of its own but does not disqualify
        // the message either: "asante sana", "ok then".
        return in_array($word, ['sana', 'tu', 'kwa', 'you', 'then', 'so', 'very', 'much'], true)
            ? 'ack'
            : null;
    }

    /**
     * What to say back to a pleasantry.
     *
     * Short, and it leaves the door open rather than closing the
     * conversation — somebody who says thanks often has one more question and
     * needs to feel invited to ask it.
     */
    public static function courtesyReply(string $kind, string $locale): string
    {
        $replies = [
            'thanks' => [
                'en' => 'Any time. Anything else you want to know?',
                'sw' => 'Karibu sana. Kuna kingine ungependa kujua?',
            ],
            'ack' => [
                'en' => 'Good. Ask me anything else you need.',
                'sw' => 'Vizuri. Niulize kingine chochote unachohitaji.',
            ],
            'greeting' => [
                'en' => 'Hello 👋 What would you like to know about the bots?',
                'sw' => 'Habari 👋 Ungependa kujua nini kuhusu bots?',
            ],
            'bye' => [
                'en' => 'Take care. We are here whenever you need us.',
                'sw' => 'Kwaheri. Tupo hapa wakati wowote utakapotuhitaji.',
            ],
            // Said in the language just switched to, which is the whole proof
            // that the request landed.
            'switched' => [
                'en' => 'Of course — English it is. What would you like to know?',
                'sw' => 'Sawa kabisa — tutaendelea kwa Kiswahili. Ungependa kujua nini?',
            ],
        ];

        return $replies[$kind][$locale] ?? $replies[$kind]['en'] ?? $replies['ack']['en'];
    }

    /**
     * What to say when there is no answer.
     *
     * It admits it plainly and offers the way forward, because the alternative
     * — a confident guess — is the single failure this whole assistant is
     * built to avoid.
     */
    public static function fallback(string $locale): string
    {
        return $locale === 'sw'
            ? 'Samahani, sina taarifa hiyo kwenye knowledge base ya Auto Resellers Hub, na sitaki kukupa jibu ambalo si sahihi. Ungependa kuzungumza na mtu wa timu yetu?'
            : 'I do not have that in the Auto Resellers Hub knowledge base, and I would rather not guess at it. Would you like to put the question to someone on our team?';
    }
}
