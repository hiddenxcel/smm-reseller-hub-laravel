<?php

namespace App\Services\Bots;

/**
 * How much WhatsApp will take in each part of a message.
 *
 * Meta does not trim an over-long field politely: for most of them it refuses
 * the whole message, and the customer gets nothing. So every field is brought
 * within its limit before it is sent, and when something has to be cut it is
 * cut between words with an ellipsis, not through the middle of one.
 *
 * The screens where a reseller types these texts show the same numbers (see
 * resources/js/components/CharLimit.tsx), so what they are told matches what
 * happens.
 */
final class WhatsAppLimits
{
    /** A plain text message. */
    public const TEXT = 4096;

    /** The body of a list or a button message. */
    public const INTERACTIVE_BODY = 1024;

    /** The footer of a list or a button message. */
    public const FOOTER = 60;

    /** A picture's caption. */
    public const CAPTION = 1024;

    /** The button that opens a list. */
    public const LIST_BUTTON = 20;

    /** A list section's heading. */
    public const SECTION_TITLE = 24;

    /** One row of a list: what the customer taps, and the line under it. */
    public const ROW_TITLE = 24;

    public const ROW_DESCRIPTION = 72;

    /** A reply button. */
    public const BUTTON_TITLE = 20;

    /**
     * $text cut to at most $limit characters.
     *
     * At the last word that fits, with an ellipsis when something was cut. A
     * single word longer than the limit has no space to cut at, and is cut
     * where it stands.
     */
    public static function fit(string $text, int $limit): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        if ($limit <= 1) {
            return mb_substr($text, 0, max(0, $limit));
        }

        $room = $limit - 1; // leave a place for the ellipsis
        $head = mb_substr($text, 0, $room);

        // Cut at the last break that is not right at the start, so a long
        // opening word does not leave nothing but an ellipsis.
        $break = max(mb_strrpos($head, ' ') ?: 0, mb_strrpos($head, "\n") ?: 0);

        if ($break >= (int) floor($room * 0.4)) {
            $head = mb_substr($head, 0, $break);
        }

        return rtrim($head, " \t\n\r,.;:-—|/").'…';
    }
}