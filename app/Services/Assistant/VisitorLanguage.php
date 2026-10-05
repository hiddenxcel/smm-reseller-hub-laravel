<?php

namespace App\Services\Assistant;

/**
 * Any language a visitor to the website might write in.
 *
 * AssistantLanguage already does the careful work for English and Kiswahili —
 * the written answers exist in those two, and the matching is tuned for them.
 * This sits in front of it for everyone else. A visitor writing French or
 * Arabic is not served a written answer (there is none in their language); the
 * question goes to the model, which is told to answer in the language it was
 * asked in, and the widget's own wording follows.
 *
 * Detection is a hint, never a verdict. Script is decisive — a sentence in
 * Arabic letters is Arabic — and for the Latin-script languages a handful of
 * unmistakable words and letters decide it. Anything unclear is left to the
 * established language of the conversation, because short replies ("ok",
 * "sí", a bare link) carry no signal and must not flip the language back.
 *
 * A visitor can also simply choose, which is always better than guessing.
 */
class VisitorLanguage
{
    /**
     * The languages offered in the picker: code => [its own name, right-to-left].
     *
     * The first two are the ones with written answers. The rest are answered by
     * the model; a language not listed here is still understood if the visitor
     * writes in it, it just is not offered by name.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const CHOICES = [
        'en' => ['English', false],
        'sw' => ['Kiswahili', false],
        'fr' => ['Français', false],
        'es' => ['Español', false],
        'pt' => ['Português', false],
        'ar' => ['العربية', true],
        'hi' => ['हिन्दी', false],
        'tr' => ['Türkçe', false],
        'de' => ['Deutsch', false],
        'id' => ['Bahasa Indonesia', false],
        'ru' => ['Русский', false],
        'zh' => ['中文', false],
        'it' => ['Italiano', false],
        'ja' => ['日本語', false],
        'ko' => ['한국어', false],
    ];

    /** Names in English, for telling the model which language to use. */
    private const ENGLISH_NAMES = [
        'en' => 'English', 'sw' => 'Kiswahili (Swahili)', 'fr' => 'French', 'es' => 'Spanish',
        'pt' => 'Portuguese', 'ar' => 'Arabic', 'hi' => 'Hindi', 'tr' => 'Turkish',
        'de' => 'German', 'id' => 'Indonesian', 'ru' => 'Russian', 'zh' => 'Chinese',
        'it' => 'Italian', 'ja' => 'Japanese', 'ko' => 'Korean', 'th' => 'Thai',
        'bn' => 'Bengali', 'he' => 'Hebrew', 'el' => 'Greek',
    ];

    /** Scripts that settle the language on their own. */
    private const SCRIPTS = [
        'ar' => '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u',
        'hi' => '/[\x{0900}-\x{097F}]/u',
        'bn' => '/[\x{0980}-\x{09FF}]/u',
        'th' => '/[\x{0E00}-\x{0E7F}]/u',
        'he' => '/[\x{0590}-\x{05FF}]/u',
        'el' => '/[\x{0370}-\x{03FF}]/u',
        'ru' => '/[\x{0400}-\x{04FF}]/u',
        // Kana before Han: Japanese text mixes both, and kana is what separates it.
        'ja' => '/[\x{3040}-\x{30FF}]/u',
        'ko' => '/[\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}]/u',
        'zh' => '/[\x{4E00}-\x{9FFF}]/u',
    ];

    /**
     * Words that, with a second, make a Latin-script sentence one language.
     *
     * Chosen for words that are theirs alone. "no", "me" and "a" are in half of
     * them and prove nothing; "gracias" and "combien" prove it.
     *
     * @var array<string, array<int, string>>
     */
    private const MARKERS = [
        'fr' => ['bonjour', 'bonsoir', 'merci', 'salut', 'combien', 'comment', 'pourquoi', 'quel', 'quelle', 'je', 'vous', 'nous', 'est', 'les', 'des', 'une', 'pour', 'avec', 'prix', 'tarif', 'puis', 'voudrais', 'peux', 'besoin', "c'est", 'oui', 'non', 'aide', 'compte'],
        'es' => ['hola', 'gracias', 'cuánto', 'cuanto', 'cómo', 'como', 'qué', 'por', 'favor', 'precio', 'necesito', 'quiero', 'puedo', 'tengo', 'ayuda', 'cuenta', 'buenas', 'tienen', 'sí', 'usted', 'para', 'los', 'las', 'una', 'del', 'está', 'funciona'],
        'pt' => ['olá', 'ola', 'obrigado', 'obrigada', 'quanto', 'como', 'você', 'voce', 'preço', 'preco', 'preciso', 'quero', 'posso', 'tenho', 'ajuda', 'conta', 'bom', 'dia', 'não', 'nao', 'uma', 'para', 'funciona', 'vocês'],
        'de' => ['hallo', 'danke', 'bitte', 'wie', 'viel', 'preis', 'ich', 'kann', 'brauche', 'möchte', 'moechte', 'und', 'nicht', 'ist', 'das', 'der', 'die', 'wie viel', 'kosten', 'hilfe', 'konto', 'funktioniert', 'guten'],
        'it' => ['ciao', 'grazie', 'quanto', 'prezzo', 'vorrei', 'come', 'posso', 'ho', 'bisogno', 'aiuto', 'conto', 'buongiorno', 'sono', 'non', 'una', 'per', 'funziona', 'costa', 'quali'],
        'tr' => ['merhaba', 'teşekkür', 'tesekkur', 'teşekkürler', 'fiyat', 'nasıl', 'nasil', 'kaç', 'kac', 'istiyorum', 'yardım', 'yardim', 'hesap', 'için', 'icin', 'bir', 'değil', 'evet', 'hayır', 'selam', 'ücret', 'ne kadar'],
        'id' => ['halo', 'terima', 'kasih', 'harga', 'bagaimana', 'berapa', 'saya', 'bisa', 'ingin', 'bantuan', 'akun', 'selamat', 'tidak', 'apa', 'untuk', 'dengan', 'yang', 'cara', 'biaya', 'mau', 'pagi'],
    ];

    /**
     * Letters that belong to one language among the Latin-script ones.
     *
     * One of these is worth more than a marker word. They are also what makes
     * "tesekkur" typed without accents the harder case, which the markers
     * above cover with both spellings.
     *
     * @var array<string, string>
     */
    private const LETTERS = [
        'es' => '/[ñ¿¡]/u',
        'pt' => '/[ãõ]/u',
        'de' => '/[ß]/u',
        'tr' => '/[ğışİ]/u',
        'fr' => '/[œ]/u',
    ];

    /**
     * What each language is called when somebody asks for it by name.
     *
     * English and Kiswahili are not here: AssistantLanguage already answers
     * "ongea kiswahili" and "speak English", and a second reading of the same
     * sentence would disagree with it.
     *
     * @var array<string, array<int, string>>
     */
    private const NAMES = [
        'fr' => ['french', 'français', 'francais', 'kifaransa'],
        'es' => ['spanish', 'español', 'espanol', 'kihispania'],
        'pt' => ['portuguese', 'português', 'portugues', 'kireno'],
        'ar' => ['arabic', 'عربي', 'العربية', 'kiarabu'],
        'hi' => ['hindi', 'हिन्दी', 'kihindi'],
        'tr' => ['turkish', 'türkçe', 'turkce', 'kituruki'],
        'de' => ['german', 'deutsch', 'kijerumani'],
        'id' => ['indonesian', 'indonesia', 'bahasa'],
        'ru' => ['russian', 'русский', 'kirusi'],
        'zh' => ['chinese', 'mandarin', '中文', 'kichina'],
        'it' => ['italian', 'italiano', 'kiitaliano'],
        'ja' => ['japanese', '日本語', 'kijapani'],
        'ko' => ['korean', '한국어', 'kikorea'],
    ];

    /** Stems that turn a language's name into a request for it. */
    private const ASKING = ['speak', 'reply', 'answer', 'talk', 'writ', 'switch', 'use', 'change', 'ongea', 'jibu', 'tumia', 'sema', 'parle', 'habla', 'fala'];

    /**
     * The language they asked to be answered in, when it is one of these.
     *
     * Naming a language is not asking for it ("my customers speak French"), so
     * a verb is required too, and a sentence naming two languages is a
     * description rather than a request.
     */
    public static function requested(string $text): ?string
    {
        $words = AssistantLanguage::words($text);

        $named = [];

        foreach (self::NAMES as $code => $names) {
            if (array_intersect($words, array_map('mb_strtolower', $names)) !== []
                || collect($names)->contains(fn (string $name) => preg_match('/[^\x00-\x7F]/', $name) === 1 && str_contains($text, $name))) {
                $named[] = $code;
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

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::CHOICES);
    }

    /** A code the picker offers, or null for "decide for me". */
    public static function normalize(?string $code): ?string
    {
        $code = $code === null ? null : strtolower(trim($code));

        return $code !== null && array_key_exists($code, self::CHOICES) ? $code : null;
    }

    public static function isRtl(string $code): bool
    {
        return (self::CHOICES[$code][1] ?? false) === true;
    }

    /** The name in English, for the instruction to the model. */
    public static function englishName(string $code): string
    {
        return self::ENGLISH_NAMES[$code] ?? strtoupper($code);
    }

    /**
     * A language other than English and Kiswahili, if the text is plainly in one.
     *
     * Null means "not obviously another language" — which includes English,
     * Kiswahili, and anything too short to tell.
     */
    public static function detect(string $text): ?string
    {
        // A link or a handle is not a sentence in any language.
        $prose = trim(preg_replace('#\S*[./@]\S*#u', ' ', $text) ?? $text);

        if ($prose === '') {
            return null;
        }

        foreach (self::SCRIPTS as $code => $pattern) {
            if (preg_match($pattern, $prose) === 1) {
                // Kana wins over Han, and Han alone means Chinese.
                return $code;
            }
        }

        $words = AssistantLanguage::words($prose);

        if ($words === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach (self::MARKERS as $code => $markers) {
            $score = count(array_intersect($words, $markers));

            if (isset(self::LETTERS[$code]) && preg_match(self::LETTERS[$code], mb_strtolower($prose)) === 1) {
                $score += 2;
            }

            if ($score > $bestScore) {
                $best = $code;
                $bestScore = $score;
            }
        }

        // One distinctive hit is enough for a very short message ("gracias",
        // "bonjour"); a longer one needs two, since a single word like "no" or
        // "una" shows up in sentences of several languages.
        $needed = count($words) <= 2 ? 1 : 2;

        // Kiswahili decisive words beat a stray overlap: "bei ngapi" shares
        // nothing with these lists, but "una" is in Italian, Spanish and
        // Kiswahili at once.
        if ($bestScore >= $needed && AssistantLanguage::detect($prose) !== 'sw') {
            return $best;
        }

        return null;
    }

    /**
     * Which language this turn is in.
     *
     * Order of authority: the visitor's explicit choice; a sentence plainly in
     * some other language; the language the conversation has already
     * established, unless this turn clearly says otherwise; and finally the
     * English/Kiswahili detection that was already here.
     *
     * @param  string|null  $chosen  what the picker sent, already normalised
     */
    public static function resolve(string $text, ?string $current, ?string $chosen): string
    {
        if ($chosen !== null) {
            return $chosen;
        }

        // "please reply in French" is an instruction, and it wins over what
        // language the instruction itself happened to be written in.
        $asked = self::requested($text);

        if ($asked !== null) {
            return $asked;
        }

        $detected = self::detect($text);

        if ($detected !== null) {
            return $detected;
        }

        $established = self::normalize($current);

        // Already in some third language, and nothing here says to leave it.
        if ($established !== null && ! in_array($established, AssistantLanguage::SUPPORTED, true)) {
            $prose = preg_replace('#\S*[./@]\S*#u', ' ', $text) ?? $text;

            if (AssistantLanguage::detect($text) === 'sw') {
                return 'sw';
            }

            // A full English sentence is a deliberate switch back; anything
            // shorter is a reply in the language we were already speaking.
            if (count(AssistantLanguage::subject(AssistantLanguage::words($prose))) >= 4) {
                return 'en';
            }

            return $established;
        }

        return AssistantLanguage::forConversation($text, $current);
    }

    /**
     * What to say when there is no answer, in the visitor's own language.
     *
     * Static on purpose: this is what appears when the model could not be
     * reached, so it cannot depend on the model to be written.
     */
    public static function fallback(string $code): string
    {
        $text = [
            'fr' => "Je n'ai pas cette information pour le moment et je préfère ne pas deviner. Voulez-vous poser la question à quelqu'un de notre équipe ?",
            'es' => 'No tengo esa información ahora mismo y prefiero no adivinar. ¿Quieres hacerle la pregunta a alguien de nuestro equipo?',
            'pt' => 'Não tenho essa informação agora e prefiro não adivinhar. Quer fazer a pergunta a alguém da nossa equipe?',
            'ar' => 'ليست لدي هذه المعلومة الآن ولا أريد التخمين. هل تود أن تسأل أحد أعضاء فريقنا؟',
            'hi' => 'अभी मेरे पास यह जानकारी नहीं है और मैं अंदाज़ा नहीं लगाना चाहता। क्या आप हमारी टीम के किसी व्यक्ति से पूछना चाहेंगे?',
            'tr' => 'Şu anda bu bilgiye sahip değilim ve tahmin yürütmek istemem. Sorunuzu ekibimizden birine iletmemizi ister misiniz?',
            'de' => 'Diese Information habe ich im Moment nicht, und ich möchte nicht raten. Möchten Sie die Frage jemandem aus unserem Team stellen?',
            'id' => 'Saya belum punya informasi itu dan tidak mau menebak. Mau bertanya langsung ke seseorang di tim kami?',
            'ru' => 'У меня сейчас нет этой информации, и я не хочу гадать. Хотите задать вопрос кому-то из нашей команды?',
            'zh' => '我目前没有这方面的信息，也不想随便猜测。您想把问题交给我们团队的同事吗？',
            'it' => 'Al momento non ho questa informazione e preferisco non tirare a indovinare. Vuoi fare la domanda a qualcuno del nostro team?',
            'ja' => '今は その情報を持っておらず、推測で答えたくありません。チームの担当者に質問してみますか？',
            'ko' => '지금은 해당 정보가 없고 추측으로 답하고 싶지 않아요. 저희 팀원에게 질문해 보시겠어요?',
        ];

        return $text[$code] ?? AssistantLanguage::fallback('en');
    }
}
