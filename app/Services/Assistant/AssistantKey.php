<?php

namespace App\Services\Assistant;

use App\Models\PlatformSecret;

/**
 * The one place that answers "can the assistant run?".
 *
 * Six screens asked that question and each read the config directly, which
 * meant adding a second place a key could come from would have been six edits
 * and a chance to miss one — with the failure being a widget that renders and
 * then cannot answer, the exact thing it is designed never to do.
 */
class AssistantKey
{
    private const SECRET = 'platform_deepseek_key';

    /** The key in force, or null when the assistant is switched off. */
    public static function get(): ?string
    {
        return PlatformSecret::value(self::SECRET);
    }

    /**
     * Whether to render the widget at all.
     *
     * Read by every public page. A chat that cannot answer reads as a broken
     * product rather than a missing feature, so with no key the widget is not
     * there at all.
     */
    public static function isReady(): bool
    {
        return filled(self::get());
    }

    /**
     * Whether the chat widget is shown on public pages.
     *
     * Not the same question as isReady(). The model is what lets the assistant
     * answer anything and in any language, but it is not what makes it
     * useful: the written answers cover the questions most visitors have, work
     * with no key at all, and when neither can help the widget offers a person.
     * Hiding the whole thing because the model is not configured turned a
     * working help desk off for want of its premium tier.
     *
     * Switch it off outright with ASSISTANT_ALWAYS_ON=false, in which case it
     * follows the key again.
     */
    public static function widgetEnabled(): bool
    {
        return (bool) config('assistant.always_on', true) || self::isReady();
    }

    /** env | database | stored-disabled | none — see PlatformSecret::source(). */
    public static function source(): string
    {
        return PlatformSecret::source(self::SECRET);
    }

    /** The last four characters, for telling two keys apart while rotating. */
    public static function hint(): ?string
    {
        return PlatformSecret::hint(self::SECRET);
    }

    /** The row an owner edits, created on first use. */
    public static function secret(): PlatformSecret
    {
        return PlatformSecret::firstOrNew(['key' => self::SECRET]);
    }
}
