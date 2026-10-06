<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Bots\WhatsAppCloudMessenger;
use App\Services\Bots\WhatsAppLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp refuses a message with an over-long field, so nothing is sent
 * longer than it allows — and when text has to be shortened it is shortened
 * between words, not through the middle of one.
 */
class WhatsAppLimitsTest extends TestCase
{
    use RefreshDatabase;

    // ---- fit() -----------------------------------------------------------

    public function test_text_within_the_limit_is_left_alone(): void
    {
        $this->assertSame('Instagram Followers', WhatsAppLimits::fit('Instagram Followers', 24));
        $this->assertSame('exactly twenty-four chars', WhatsAppLimits::fit('exactly twenty-four chars', 25));
    }

    public function test_it_is_trimmed_before_it_is_measured(): void
    {
        $this->assertSame('hello', WhatsAppLimits::fit("  hello \n", 5));
    }

    public function test_longer_text_is_cut_between_words_with_an_ellipsis(): void
    {
        $result = WhatsAppLimits::fit('Instagram Followers | Real | No Drop | 365 Days Refill', 24);

        $this->assertLessThanOrEqual(24, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
        // Whole words only, and no dangling separator before the ellipsis.
        $this->assertSame('Instagram Followers…', $result);
    }

    public function test_it_never_cuts_through_the_middle_of_a_word(): void
    {
        $text = 'Premium quality followers with lifetime guarantee included';

        for ($limit = 12; $limit < mb_strlen($text); $limit++) {
            $result = WhatsAppLimits::fit($text, $limit);
            $withoutEllipsis = rtrim(mb_substr($result, 0, -1));

            $this->assertLessThanOrEqual($limit, mb_strlen($result), "limit {$limit}");
            $this->assertTrue(
                str_starts_with($text, $withoutEllipsis)
                && (mb_strlen($text) === mb_strlen($withoutEllipsis) || $text[strlen($withoutEllipsis)] === ' '),
                "cut mid-word at limit {$limit}: {$result}",
            );
        }
    }

    public function test_one_word_longer_than_the_limit_is_cut_where_it_stands(): void
    {
        $result = WhatsAppLimits::fit(str_repeat('a', 40), 10);

        $this->assertSame(10, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
    }

    public function test_multibyte_text_and_emoji_are_counted_as_characters_not_bytes(): void
    {
        $text = 'Huduma ya wafuasi wa Instagram — haraka na salama sana 🚀🚀🚀';

        $result = WhatsAppLimits::fit($text, 20);

        $this->assertLessThanOrEqual(20, mb_strlen($result));
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    // ---- what is actually sent -------------------------------------------

    private function messenger(): WhatsAppCloudMessenger
    {
        $tenant = Tenant::factory()->create();

        return new WhatsAppCloudMessenger(
            phoneNumberId: '123',
            token: 'token',
            tenantId: $tenant->id,
            footer: '© '.str_repeat('A very long business name ', 5),
        );
    }

    private function sentPayload(): array
    {
        $payload = null;

        Http::assertSent(function ($request) use (&$payload) {
            $payload = $request->data();

            return true;
        });

        return $payload;
    }

    public function test_a_long_text_message_stays_within_what_whatsapp_accepts(): void
    {
        Http::fake();

        $this->messenger()->sendText('255700000001', str_repeat('word ', 2000));

        $body = $this->sentPayload()['text']['body'];
        $this->assertLessThanOrEqual(WhatsAppLimits::TEXT, mb_strlen($body));
    }

    public function test_every_field_of_a_list_is_brought_within_its_limit(): void
    {
        Http::fake();
        $long = 'Instagram Followers | Real Mobile App | No Drop | 365 Days Refill Guaranteed Service';

        $this->messenger()->sendList(
            '255700000001',
            str_repeat('Choose a service from the list below please. ', 40),
            'A very long list button label',
            'A very long section heading here',
            [['id' => 'svc_1', 'title' => $long, 'description' => str_repeat('USD per 1000 ', 20)]],
        );

        $interactive = $this->sentPayload()['interactive'];
        $row = $interactive['action']['sections'][0]['rows'][0];

        $this->assertLessThanOrEqual(WhatsAppLimits::INTERACTIVE_BODY, mb_strlen($interactive['body']['text']));
        $this->assertLessThanOrEqual(WhatsAppLimits::FOOTER, mb_strlen($interactive['footer']['text']));
        $this->assertLessThanOrEqual(WhatsAppLimits::LIST_BUTTON, mb_strlen($interactive['action']['button']));
        $this->assertLessThanOrEqual(WhatsAppLimits::SECTION_TITLE, mb_strlen($interactive['action']['sections'][0]['title']));
        $this->assertLessThanOrEqual(WhatsAppLimits::ROW_TITLE, mb_strlen($row['title']));
        $this->assertLessThanOrEqual(WhatsAppLimits::ROW_DESCRIPTION, mb_strlen($row['description']));
        // The id is what the bot matches on: never touched.
        $this->assertSame('svc_1', $row['id']);
    }

    public function test_button_titles_and_bodies_are_fitted_too(): void
    {
        Http::fake();

        $this->messenger()->sendButtons(
            '255700000001',
            str_repeat('Please confirm your order now. ', 60),
            [['id' => 'confirm_yes', 'title' => 'Yes, place my order right now']],
        );

        $interactive = $this->sentPayload()['interactive'];
        $this->assertLessThanOrEqual(WhatsAppLimits::INTERACTIVE_BODY, mb_strlen($interactive['body']['text']));
        $this->assertLessThanOrEqual(WhatsAppLimits::BUTTON_TITLE, mb_strlen($interactive['action']['buttons'][0]['reply']['title']));
        $this->assertSame('confirm_yes', $interactive['action']['buttons'][0]['reply']['id']);
    }

    public function test_a_picture_caption_stays_within_its_limit(): void
    {
        Http::fake();

        $this->messenger()->sendImage('255700000001', 'https://hub.test/a.png', str_repeat('Open your profile. ', 100));

        $this->assertLessThanOrEqual(WhatsAppLimits::CAPTION, mb_strlen($this->sentPayload()['image']['caption']));
    }

    // ---- link help is held to what a caption can carry --------------------

    private function addService(string $linkHelp)
    {
        $tenant = Tenant::factory()->create();

        return $this->actingAs($tenant, 'tenant')
            ->from(route('services.index'))
            ->post(route('services.store'), [
                'name' => 'Hand-added',
                'platform' => 'Instagram',
                'provider_service_id' => '4242',
                'my_price' => '2.5',
                'min_quantity' => 100,
                'max_quantity' => 10000,
                'link_instructions' => $linkHelp,
            ]);
    }

    public function test_link_help_up_to_six_hundred_characters_is_accepted(): void
    {
        $this->addService(str_repeat('a', 600))->assertSessionHasNoErrors();
    }

    public function test_link_help_longer_than_a_caption_can_carry_is_refused(): void
    {
        $this->addService(str_repeat('a', 601))->assertSessionHasErrors('link_instructions');
    }
}
