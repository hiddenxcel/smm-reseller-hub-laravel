<?php

namespace App\Services\Bots\Telegram;

/**
 * What Telegram will take in each part of a message.
 */
final class TelegramLimits
{
    public const TEXT = 4096;

    public const CAPTION = 1024;

    /** callback_data, in bytes. Longer ids are swapped for a short token. */
    public const CALLBACK_BYTES = 64;

    /** A button's label stays readable on a phone well before Telegram's own cut. */
    public const BUTTON_TITLE = 60;
}
