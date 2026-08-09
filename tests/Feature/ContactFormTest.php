<?php

namespace Tests\Feature;

use App\Notifications\ContactMessageReceived;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The contact form, and the one thing about it that can silently lose a
 * customer: where the message is delivered.
 *
 * It used to go to config('mail.from.address') — the noreply@ everything is
 * sent from, which has no mailbox behind it and nobody reading it. A visitor
 * filled in the form, was told "we have your message", and nothing arrived
 * anywhere. There was no test, which is how that survived.
 */
class ContactFormTest extends TestCase
{
    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Asha Mwinyi',
            'email' => 'asha@example.com',
            'subject' => 'Question about pricing',
            'message' => 'Hello, I would like to know more about your plans.',
        ], $overrides);
    }

    private function url(): string
    {
        return Route::has('contact.store') ? route('contact.store') : '/contact';
    }

    /**
     * The regression that matters: delivery goes to the contact mailbox, not
     * to the address outgoing mail happens to be sent from.
     */
    public function test_a_message_goes_to_the_contact_mailbox_not_the_noreply(): void
    {
        Notification::fake();

        config([
            'mail.from.address' => 'noreply@smmresellershub.com',
            'mail.contact_to' => 'info@smmresellershub.com',
        ]);

        $this->post($this->url(), $this->payload())
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(
            ContactMessageReceived::class,
            function ($notification, $channels, $notifiable) {
                $this->assertSame(
                    'info@smmresellershub.com',
                    $notifiable->routes['mail'],
                    'the contact form delivered to the noreply address again',
                );

                return true;
            },
        );
    }

    /**
     * A deployment that never sets the contact address should still work the
     * way it always did, rather than refusing to send anything.
     */
    public function test_it_falls_back_to_the_from_address(): void
    {
        Notification::fake();

        config([
            'mail.from.address' => 'hello@example.com',
            'mail.contact_to' => 'hello@example.com',
        ]);

        $this->post($this->url(), $this->payload())
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(
            ContactMessageReceived::class,
            function ($notification, $channels, $notifiable) {
                $this->assertSame('hello@example.com', $notifiable->routes['mail']);

                return true;
            },
        );
    }

    /** The honeypot: a field no person can see, so anything in it is a bot. */
    public function test_a_filled_honeypot_is_refused(): void
    {
        Notification::fake();

        $this->post($this->url(), $this->payload(['website' => 'http://spam.example']))
            ->assertSessionHasErrors('website');

        Notification::assertNothingSent();
    }

    public function test_an_incomplete_message_is_refused(): void
    {
        Notification::fake();

        $this->post($this->url(), $this->payload(['message' => 'hi']))
            ->assertSessionHasErrors('message');

        Notification::assertNothingSent();
    }

    public function test_a_bad_email_is_refused(): void
    {
        Notification::fake();

        $this->post($this->url(), $this->payload(['email' => 'not-an-address']))
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    /**
     * Answering has to reach the visitor, not the form. The From stays ours —
     * sending as the visitor would fail SPF for their domain.
     */
    public function test_the_reply_goes_back_to_whoever_wrote_in(): void
    {
        $notification = new ContactMessageReceived(
            name: 'Asha Mwinyi',
            email: 'asha@example.com',
            subject: 'Question about pricing',
            body: 'Hello there, tell me more please.',
        );

        $mail = $notification->toMail((object) []);

        $this->assertSame([['asha@example.com', 'Asha Mwinyi']], $mail->replyTo);
        $this->assertStringContainsString('Question about pricing', $mail->subject);
    }
}
