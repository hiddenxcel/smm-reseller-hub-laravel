<?php

namespace App\Services\Bots;

/**
 * Whether the bot is being rehearsed rather than answering a real customer.
 *
 * The in-browser simulator drives the real handlers, so the handlers have to
 * know when the thing on the other end is a rehearsal: the places where they
 * would reach outside the database — the panel, a payment gateway — must not
 * do so. Everything inside the database is handled by the simulator rolling
 * its transaction back; this flag covers the rest.
 *
 * A static flag rather than a container binding because it is read from deep
 * inside handlers that are constructed by contract with (tenant, messenger),
 * and it is only ever set for the length of one synchronous call.
 */
final class BotSimulation
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        $previous = self::$active;
        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = $previous;
        }
    }
}
