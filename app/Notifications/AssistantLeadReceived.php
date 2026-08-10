<?php

namespace App\Notifications;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody asked the website assistant for a human.
 *
 * Unlike the contact form, this arrives with everything they asked before
 * giving up on the bot — which is usually the more useful half. Whoever
 * answers should be able to open the mail and already know what the
 * conversation was about, rather than starting it again from "how can I help".
 */
class AssistantLeadReceived extends Notification implements ShouldQueue
{
    use Queueable;

    /** Enough to see what they were after without pasting a whole session. */
    private const EXCERPT_TURNS = 8;

    public function __construct(private AssistantConversation $conversation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("[Assistant] {$this->conversation->lead_name} asked for a human")
            ->greeting('Someone wants to talk')
            ->line("**Name:** {$this->conversation->lead_name}")
            ->line("**WhatsApp:** {$this->conversation->lead_phone}");

        if (filled($this->conversation->page)) {
            $mail->line("**Reading:** {$this->conversation->page}");
        }

        if (filled($this->conversation->lead_message)) {
            $mail->line('---')->line($this->conversation->lead_message);
        }

        $transcript = $this->transcript();

        if ($transcript !== []) {
            $mail->line('---')->line('**What they asked the assistant:**');

            foreach ($transcript as $line) {
                $mail->line($line);
            }
        }

        return $mail->salutation('Reply on WhatsApp — that is where they asked to be reached.');
    }

    /** @return array<int, string> */
    private function transcript(): array
    {
        return $this->conversation->messages()
            ->latest('id')
            ->limit(self::EXCERPT_TURNS)
            ->get()
            ->reverse()
            ->map(function (AssistantMessage $message) {
                $who = $message->role === 'user' ? 'They asked' : 'Assistant';

                return "*{$who}:* {$message->content}";
            })
            ->values()
            ->all();
    }
}
