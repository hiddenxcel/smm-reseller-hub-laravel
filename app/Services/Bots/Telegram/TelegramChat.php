<?php

namespace App\Services\Bots\Telegram;

/**
 * How a Telegram chat is known inside the app.
 *
 * Everything is keyed on a short "phone" string (customers, conversations,
 * orders, payments), so a Telegram chat stands in for one as `tg<chat_id>`.
 * It starts with letters, so it can never be mistaken for a phone number, and
 * a chat id is at most about 16 digits, well inside the 30 the columns allow.
 * It is turned back into the chat id only when a message is sent.
 */
final class TelegramChat
{
    public static function key(int|string $chatId): string
    {
        return 'tg'.$chatId;
    }

    public static function isKey(string $value): bool
    {
        return preg_match('/^tg-?\d{1,18}$/', $value) === 1;
    }

    /** The chat id behind a key, or null when it is not one. */
    public static function chatId(string $key): ?string
    {
        return self::isKey($key) ? substr($key, 2) : null;
    }
}
