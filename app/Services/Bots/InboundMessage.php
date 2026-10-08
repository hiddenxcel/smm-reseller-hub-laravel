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
        /**
         * Set when Meta sent no phone number: a WhatsApp user who hides theirs
         * is known to a business only by this business-scoped ID. `from` is
         * empty until the router gives it a short alias.
         */
        public ?string $bsuid = null,
        /** The WhatsApp username, when the sender has one. */
        public ?string $username = null,
    ) {}

    /** The same message, now with the key the rest of the app knows the sender by. */
    public function withFrom(string $from): self
    {
        return new self(
            $this->phoneNumberId,
            $from,
            $this->text,
            $this->providerMessageId,
            $this->profileName,
            $this->bsuid,
            $this->username,
        );
    }

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

        // No phone number: the sender hides it, and Meta gives only an ID.
        $bsuid = $from === '' ? (string) ($message['from_user_id'] ?? data_get($value, 'contacts.0.user_id') ?? '') : '';

        if ($from === '' && $bsuid === '') {
            return null;
        }

        return new self(
            phoneNumberId: (string) $phoneNumberId,
            from: $from,
            text: self::extractText($message),
            providerMessageId: isset($message['id']) ? (string) $message['id'] : null,
            profileName: self::cleanName(data_get($value, 'contacts.0.profile.name')),
            bsuid: $bsuid !== '' ? $bsuid : null,
            username: self::cleanName(data_get($value, 'contacts.0.profile.username')),
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
