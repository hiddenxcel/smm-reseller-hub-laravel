<?php

namespace App\Services\Bots\Telegram;

/**
 * The bot's messages are written in WhatsApp's markup (*bold*, _italic_).
 * Telegram's HTML mode is the safe way to show the same thing: only the few
 * tags below are ever produced, and everything else is escaped.
 */
final class TelegramFormat
{
    public static function toHtml(string $text): string
    {
        $text = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // A marker only counts at a word edge, so snake_case words, URLs with
        // underscores and a lone asterisk are left alone.
        $text = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<b>$1</b>', $text) ?? $text;

        return preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '<i>$1</i>', $text) ?? $text;
    }

    /** The same text with the markers removed, for when HTML is refused. */
    public static function toPlain(string $text): string
    {
        $text = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '$1', $text) ?? $text;

        return preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '$1', $text) ?? $text;
    }
}
