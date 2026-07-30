<?php

namespace App\Services\Payments;

use Illuminate\Support\Arr;

/**
 * Reads config/gateways.php. Everything that needs to know what a gateway is
 * or does goes through here rather than reaching into config directly.
 */
class Gateway
{
    /** @return array<string, array> */
    public static function all(): array
    {
        return config('gateways', []);
    }

    public static function exists(string $code): bool
    {
        return Arr::has(self::all(), $code);
    }

    /** Wired end-to-end, as opposed to selectable-but-not-built-yet. */
    public static function isReady(string $code): bool
    {
        return (bool) Arr::get(self::all(), "{$code}.ready", false);
    }

    /** No webhook: the payer reports a reference we verify ourselves. */
    public static function isVerify(string $code): bool
    {
        return (bool) Arr::get(self::all(), "{$code}.verify", false);
    }

    /** Mobile money pushes a prompt to a handset, so we must ask for a number. */
    public static function needsPhone(string $code): bool
    {
        return Arr::get(self::all(), "{$code}.type") === 'mobile';
    }

    public static function label(string $code): string
    {
        return Arr::get(self::all(), "{$code}.label", ucfirst($code));
    }
}
