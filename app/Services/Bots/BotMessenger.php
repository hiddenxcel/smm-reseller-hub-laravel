<?php

namespace App\Services\Bots;

/**
 * The send surface a bot handler needs, so one handler runs unchanged over
 * WhatsApp or Telegram.
 *
 * This was the cleanest seam in the old platform and is kept as-is: the order
 * flow is written against this interface and knows nothing about which channel
 * it is speaking on.
 */
interface BotMessenger
{
    public function sendText(string $to, string $message, ?string $templateKey = null): bool;

    /**
     * A picker list. WhatsApp caps these at 10 rows per section, so callers
     * must paginate rather than assume the whole catalogue fits.
     *
     * @param  array<int, array{id: string, title: string, description?: string}>  $rows
     */
    public function sendList(
        string $to,
        string $bodyText,
        string $buttonText,
        string $sectionTitle,
        array $rows,
        ?string $templateKey = null,
    ): bool;

    /** @param array<int, array{id: string, title: string}> $buttons */
    public function sendButtons(string $to, string $bodyText, array $buttons, ?string $templateKey = null): bool;

    /** A picture with a caption, from a public URL. */
    public function sendImage(string $to, string $imageUrl, string $caption, ?string $templateKey = null): bool;

    public function markReadWithTyping(string $messageId): bool;
}
