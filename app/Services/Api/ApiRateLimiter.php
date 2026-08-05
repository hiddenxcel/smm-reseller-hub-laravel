<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use Illuminate\Support\Facades\DB;

/**
 * A per-key request budget, in a fixed one-minute window.
 *
 * This uses the `rate_limits` table rather than Laravel's cache-backed
 * RateLimiter deliberately: the cache store is Redis in production but an
 * array in tests and on a box where Redis is down, and a limiter that
 * silently stops limiting is worse than one that costs a row. The counter is
 * a place where "it quietly did nothing" must not be possible.
 *
 * The window is fixed rather than sliding — a caller can burst up to twice
 * the limit across a boundary. That is accepted: the limit exists to stop a
 * runaway loop, and a sliding window costs a row per request to close a gap
 * nobody is exploiting.
 */
class ApiRateLimiter
{
    private const ACTION = 'api';

    /**
     * Count this request against the key's budget.
     *
     * The increment is conditional on the row still being in the same window,
     * so two concurrent requests cannot both reset the counter and let the
     * limit through — the loser's update matches nothing and it re-reads.
     *
     * @return bool True if the request is within budget.
     */
    public function attempt(ApiKey $key): bool
    {
        $identifier = 'key:'.$key->getKey();
        $limit = $key->limitPerMinute();
        $now = now();
        $windowStart = $now->copy()->startOfMinute();

        $row = DB::table('rate_limits')
            ->where('identifier', $identifier)
            ->where('action', self::ACTION)
            ->first();

        if ($row === null) {
            // A unique index would be the cleaner guard here, but the table
            // predates this use and is shared; a duplicate row would only
            // mean a caller briefly gets a second budget, so the insert is
            // allowed to race.
            DB::table('rate_limits')->insert([
                'identifier' => $identifier,
                'action' => self::ACTION,
                'attempts' => 1,
                'window_start' => $windowStart,
            ]);

            return true;
        }

        // A new minute: reset rather than accumulate. Conditional on the old
        // window so a concurrent reset does not drop the other's count.
        if ($row->window_start < $windowStart) {
            $reset = DB::table('rate_limits')
                ->where('id', $row->id)
                ->where('window_start', $row->window_start)
                ->update(['attempts' => 1, 'window_start' => $windowStart]);

            if ($reset === 1) {
                return true;
            }

            $row = DB::table('rate_limits')->where('id', $row->id)->first();

            if ($row === null) {
                return true;
            }
        }

        if ($row->attempts >= $limit) {
            return false;
        }

        DB::table('rate_limits')
            ->where('id', $row->id)
            ->where('window_start', $row->window_start)
            ->increment('attempts');

        return true;
    }
}
