<?php

namespace Tests\Feature;

use App\Models\AssistantKnowledge;
use App\Models\AssistantMessage;
use App\Models\BlogPost;
use App\Services\Assistant\AssistantKey;
use App\Services\Assistant\PlatformContext;
use App\Services\Assistant\VisitorLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The assistant answers in the language the visitor writes, whatever it is.
 *
 * English and Kiswahili have written answers and keep them. Everyone else goes
 * to the model, which is told which language to use — and with no model
 * configured gets a plain apology in their own language rather than a reply in
 * English they may not read.
 */
class AssistantAnyLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform_deepseek_key' => 'test-key']);

        AssistantKnowledge::query()->delete();
        AssistantKnowledge::forget();
        PlatformContext::forget();
    }

    private function fakeModel(string $reply): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $reply]]]])]);
    }

    private function ask(string $message, array $extra = [])
    {
        return $this->postJson(route('assistant.ask'), ['message' => $message, ...$extra]);
    }

    // ---- recognising a language ------------------------------------------

    /** @dataProvider sentences */
    public function test_it_recognises_a_language(string $sentence, string $code): void
    {
        $this->assertSame($code, VisitorLanguage::detect($sentence));
    }

    public static function sentences(): array
    {
        return [
            'french' => ['Bonjour, combien ça coûte pour une boutique ?', 'fr'],
            'spanish' => ['Hola, ¿cuánto cuesta y cómo puedo empezar?', 'es'],
            'portuguese' => ['Olá, quanto custa e como posso começar?', 'pt'],
            'german' => ['Hallo, wie viel kostet das und kann ich es testen?', 'de'],
            'turkish' => ['Merhaba, fiyat nedir ve nasıl başlarım?', 'tr'],
            'indonesian' => ['Halo, berapa harga dan bagaimana cara memulai?', 'id'],
            'italian' => ['Ciao, quanto costa e come posso iniziare?', 'it'],
            'arabic' => ['مرحبا كم السعر', 'ar'],
            'hindi' => ['नमस्ते कीमत कितनी है', 'hi'],
            'russian' => ['Здравствуйте, сколько это стоит?', 'ru'],
            'chinese' => ['你好，多少钱？', 'zh'],
            'japanese' => ['こんにちは、料金はいくらですか', 'ja'],
            'korean' => ['안녕하세요 가격이 얼마예요', 'ko'],
        ];
    }

    /** @dataProvider notOther */
    public function test_it_does_not_mistake_english_or_kiswahili_for_something_else(string $sentence): void
    {
        $this->assertNull(VisitorLanguage::detect($sentence));
    }

    public static function notOther(): array
    {
        return [
            ['How do I connect my panel and start selling?'],
            ['Can I use my own WhatsApp number for the order bot?'],
            ['Bei ni kiasi gani na ninawezaje kuanza?'],
            ['Order Bot ni nini?'],
            ['https://instagram.com/mystore'],
            ['ok'],
            [''],
        ];
    }

    public function test_asking_for_a_language_by_name_is_an_instruction(): void
    {
        $this->assertSame('fr', VisitorLanguage::requested('please reply in French'));
        $this->assertSame('de', VisitorLanguage::requested('can you speak German?'));
        $this->assertSame('zh', VisitorLanguage::requested('use 中文 please, speak chinese'));

        // Naming a language is not asking for it.
        $this->assertNull(VisitorLanguage::requested('my customers speak French and Spanish'));
        $this->assertNull(VisitorLanguage::requested('Is there a French version?'));
    }

    public function test_a_short_reply_keeps_the_language_already_in_use(): void
    {
        $this->assertSame('fr', VisitorLanguage::resolve('ok', 'fr', null));
        $this->assertSame('fr', VisitorLanguage::resolve('https://x.com/y', 'fr', null));
        // A whole English sentence is a deliberate switch back.
        $this->assertSame('en', VisitorLanguage::resolve('How do I connect my panel and start selling?', 'fr', null));
    }

    // ---- answering --------------------------------------------------------

    public function test_a_french_question_reaches_the_model_told_to_answer_in_french(): void
    {
        $this->fakeModel('Bien sûr. Voici comment cela fonctionne.');

        $response = $this->ask('Bonjour, combien ça coûte pour une boutique ?')->assertOk();

        $response->assertJsonPath('locale', 'fr')
            ->assertJsonPath('rtl', false)
            ->assertJsonPath('answered_by', AssistantMessage::AI);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('Reply entirely in French', $request['messages'][0]['content']);

            return true;
        });
    }

    public function test_arabic_is_flagged_right_to_left(): void
    {
        $this->fakeModel('بالتأكيد.');

        $this->ask('مرحبا كم السعر')
            ->assertOk()
            ->assertJsonPath('locale', 'ar')
            ->assertJsonPath('rtl', true);
    }

    public function test_a_written_answer_is_not_served_to_someone_who_did_not_ask_in_its_language(): void
    {
        AssistantKnowledge::create([
            'topic' => 'order_bot',
            'question' => 'What is Order Bot?',
            'answer' => 'Order Bot turns your WhatsApp number into a shop.',
            'keywords' => 'order bot, orderbot',
            'status' => 'active',
        ]);

        $this->fakeModel('Order Bot transforme votre numéro WhatsApp en boutique.');

        // Written in French, so it is the model's to answer — not the English row.
        $response = $this->ask("Qu'est-ce que Order Bot ? Je voudrais savoir comment ça marche", ['lang' => 'fr'])->assertOk();

        $response->assertJsonPath('answered_by', AssistantMessage::AI);
        Http::assertSentCount(1);
    }

    public function test_the_visitors_own_choice_beats_the_guess(): void
    {
        $this->fakeModel('Claro.');

        // English words, but they picked Spanish.
        $this->ask('How much is it', ['lang' => 'es'])
            ->assertOk()
            ->assertJsonPath('locale', 'es');

        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], 'Reply entirely in Spanish'));
    }

    public function test_an_unknown_language_code_is_ignored(): void
    {
        $this->fakeModel('Sure.');

        $this->ask('How much does it cost to start selling on WhatsApp', ['lang' => 'xx'])
            ->assertOk()
            ->assertJsonPath('locale', 'en');
    }

    public function test_with_no_model_a_visitor_is_apologised_to_in_their_own_language(): void
    {
        config(['services.platform_deepseek_key' => null]);

        $this->ask('Bonjour, combien ça coûte pour une boutique ?')
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath('answered_by', AssistantMessage::FALLBACK)
            ->assertJsonPath('escalate', true)
            ->assertJsonFragment(['reply' => VisitorLanguage::fallback('fr')]);

        Http::assertNothingSent();
    }

    public function test_the_conversation_stays_in_french_across_short_replies(): void
    {
        $this->fakeModel('Oui.');

        $first = $this->ask('Bonjour, combien ça coûte pour une boutique ?')->assertOk();
        $token = $first->json('token');

        $this->ask('ok', ['token' => $token])->assertOk()->assertJsonPath('locale', 'fr');
    }

    // ---- buttons ----------------------------------------------------------

    public function test_up_to_two_buttons_come_back_and_no_more(): void
    {
        $this->fakeModel("Here you go.\n[[CTA:Try it|/try]]\n[[CTA:Pricing|/pricing]]\n[[CTA:Sign up|/register]]");

        $response = $this->ask('How do I see this working before I buy anything at all?')->assertOk();

        $this->assertSame(['/try', '/pricing'], collect($response->json('ctas'))->pluck('url')->all());
        $this->assertSame('/try', $response->json('cta.url'));
        $this->assertStringNotContainsString('[[CTA', $response->json('reply'));
    }

    public function test_a_published_guide_may_be_linked_and_a_draft_may_not(): void
    {
        BlogPost::create(['title' => 'Pricing guide', 'slug' => 'pricing-guide', 'excerpt' => 'x', 'body' => 'x', 'published_at' => now()->subDay()]);
        BlogPost::create(['title' => 'Unfinished', 'slug' => 'unfinished', 'excerpt' => 'x', 'body' => 'x', 'published_at' => null]);

        $this->assertTrue(PlatformContext::allowsPath('/blog/pricing-guide'));
        $this->assertFalse(PlatformContext::allowsPath('/blog/unfinished'));
        $this->assertFalse(PlatformContext::allowsPath('/blog/does-not-exist'));
        $this->assertFalse(PlatformContext::allowsPath('/checkout'));
        $this->assertTrue(PlatformContext::allowsPath('/try'));
    }

    // ---- what it knows ----------------------------------------------------

    public function test_the_prompt_carries_the_facts_and_the_practice_chat(): void
    {
        BlogPost::create(['title' => 'Pricing guide', 'slug' => 'pricing-guide', 'excerpt' => 'x', 'body' => 'x', 'published_at' => now()->subDay()]);

        $prompt = PlatformContext::for('en');

        // The bots' real languages and the real setup steps, read from code.
        $this->assertStringContainsString('Kiswahili', $prompt);
        $this->assertStringContainsString('Connect your panel', $prompt);
        $this->assertStringContainsString('Try your bot', $prompt);
        $this->assertStringContainsString('/try', $prompt);
        $this->assertStringContainsString('/blog/pricing-guide', $prompt);

        // Honest about what the support bot does and does not do.
        $this->assertStringContainsString('do not act on the panel by themselves', $prompt);
        $this->assertStringContainsString('The support menu is in English for now', $prompt);
    }

    public function test_the_prompt_still_forbids_inventing_prices_and_secrets(): void
    {
        $prompt = PlatformContext::for('en');

        $this->assertStringContainsString('Never invent a price', $prompt);
        $this->assertStringContainsString('Never ask for a password', $prompt);
    }

    public function test_the_widget_works_without_a_key_unless_tied_to_it(): void
    {
        config(['services.platform_deepseek_key' => null]);

        $this->assertTrue(AssistantKey::widgetEnabled());

        config(['assistant.always_on' => false]);

        $this->assertFalse(AssistantKey::widgetEnabled());
    }
}
