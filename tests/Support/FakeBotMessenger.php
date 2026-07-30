<?php

namespace Tests\Support;

use App\Services\Bots\BotMessenger;

/**
 * Records what a bot would have sent, so router and handler tests can assert
 * on the conversation without touching the WhatsApp API.
 */
class FakeBotMessenger implements BotMessenger
{
    /** @var array<int, array{type: string, to: string, body: string, templateKey: ?string}> */
    public array $sent = [];

    public array $markedRead = [];

    public function sendText(string $to, string $message, ?string $templateKey = null): bool
    {
        $this->sent[] = ['type' => 'text', 'to' => $to, 'body' => $message, 'templateKey' => $templateKey];

        return true;
    }

    public function sendList(
        string $to,
        string $bodyText,
        string $buttonText,
        string $sectionTitle,
        array $rows,
        ?string $templateKey = null,
    ): bool {
        $this->sent[] = [
            'type' => 'list',
            'to' => $to,
            'body' => $bodyText,
            'templateKey' => $templateKey,
            'rows' => $rows,
        ];

        return true;
    }

    public function sendButtons(string $to, string $bodyText, array $buttons, ?string $templateKey = null): bool
    {
        $this->sent[] = [
            'type' => 'buttons',
            'to' => $to,
            'body' => $bodyText,
            'templateKey' => $templateKey,
            'buttons' => $buttons,
        ];

        return true;
    }

    public function markReadWithTyping(string $messageId): bool
    {
        $this->markedRead[] = $messageId;

        return true;
    }

    public function nothingSent(): bool
    {
        return $this->sent === [];
    }

    public function lastBody(): ?string
    {
        return $this->sent === [] ? null : end($this->sent)['body'];
    }
}
