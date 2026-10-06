<?php

namespace App\Services\Bots;

/**
 * A normalised inbound message, so the router does not care whether it came
 * from Meta's webhook shape or Telegram's.
 */
final readonly class InboundMessage
{
    public function __construct(
        public string $phoneNumberId,
        public string $from,
        public string $text,
        public ?string $providerMessageId = null,
        /** The name on the sender's WhatsApp profile, as Meta reports it. */
        public ?string $profileName = null,
    ) {}

    /**
     * Pull a message out of a Meta webhook payload, or null when there is
     * none — status callbacks (delivered/read) share the same envelope.
     */
    public static function fromMetaPayload(array $payload): ?self
    {
        $value = data_get($payload, 'entry.0.changes.0.value');

        if (! is_array($value)) {
            return null;
        }

        $phoneNumberId = data_get($value, 'metadata.phone_number_id');
        $message = data_get($value, 'messages.0');

        if ($phoneNumberId === null || ! is_array($message)) {
            return null;
        }

        $from = (string) ($message['from'] ?? '');

        if ($from === '') {
            return null;
        }

        return new self(
            phoneNumberId: (string) $phoneNumberId,
            from: $from,
            text: self::extractText($message),
            providerMessageId: isset($message['id']) ? (string) $message['id'] : null,
            profileName: self::cleanName(data_get($value, 'contacts.0.profile.name')),
        );
    }

    /**
     * A profile name made safe to keep: no control characters, one space
     * between words, and a length that cannot fill a column. Null when nothing
     * is left.
     */
    public static function cleanName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $name = preg_replace('/[\p{C}]+/u', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return $name === '' ? null : mb_substr($name, 0, 80);
    }

    /**
     * Interactive replies carry the row/button id rather than its label —
     * the id is what the state machine matches on, so it wins over any text.
     */
    private static function extractText(array $message): string
    {
        return match ($message['type'] ?? 'text') {
            'text' => (string) data_get($message, 'text.body', ''),
            'interactive' => (string) (
                data_get($message, 'interactive.list_reply.id')
                ?? data_get($message, 'interactive.button_reply.id')
                ?? ''
            ),
            'button' => (string) (
                data_get($message, 'button.payload')
                ?? data_get($message, 'button.text')
                ?? ''
            ),
            default => '',
        };
    }
}
