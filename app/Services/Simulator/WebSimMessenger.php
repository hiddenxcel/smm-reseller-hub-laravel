<?php

namespace App\Services\Simulator;

use App\Services\Bots\BotMessenger;

/**
 * Collects what a bot would have sent, so the browser can draw it.
 *
 * Only messages addressed to the simulated customer are kept. The order bot
 * also alerts the reseller's staff numbers through the same messenger, and a
 * rehearsal must neither message those people nor show their alerts to the
 * person trying the bot.
 */
class WebSimMessenger implements BotMessenger
{
    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    public function __construct(private string $phone) {}

    public function sendText(string $to, string $message, ?string $templateKey = null): bool
    {
        if ($to === $this->phone && trim($message) !== '') {
            $this->events[] = ['type' => 'text', 'body' => $message];
        }

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
        if ($to === $this->phone) {
            $this->events[] = [
                'type' => 'list',
                'body' => $bodyText,
                'button' => $buttonText,
                'title' => $sectionTitle,
                'rows' => array_values(array_map(fn (array $row) => [
                    'id' => (string) $row['id'],
                    'title' => (string) $row['title'],
                    'description' => (string) ($row['description'] ?? ''),
                ], $rows)),
            ];
        }

        return true;
    }

    public function sendButtons(string $to, string $bodyText, array $buttons, ?string $templateKey = null): bool
    {
        if ($to === $this->phone) {
            $this->events[] = [
                'type' => 'buttons',
                'body' => $bodyText,
                'buttons' => array_values(array_map(fn (array $button) => [
                    'id' => (string) $button['id'],
                    'title' => (string) $button['title'],
                ], $buttons)),
            ];
        }

        return true;
    }

    public function markReadWithTyping(string $messageId): bool
    {
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function events(): array
    {
        return $this->events;
    }
}
