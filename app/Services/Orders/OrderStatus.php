<?php

namespace App\Services\Orders;

/**
 * Folds the many status strings panels return into four groups the reseller
 * can actually filter and reason about.
 *
 * Panels do not agree on wording — "Completed", "COMPLETE", "In progress",
 * "Processing", "Partial", "Canceled", "Refunded" all appear in the wild, and
 * the column is a free-text string because of it. Matching is on a substring,
 * case-insensitively, which is what the old platform did and what keeps a new
 * panel's spelling from silently landing in the wrong column.
 *
 * Order matters: `failed` is checked before `processing` so "Partially
 * refunded" is not read as in-progress.
 */
final class OrderStatus
{
    public const COMPLETED = 'completed';

    public const PROCESSING = 'processing';

    public const FAILED = 'failed';

    /** The catch-all — placed, but the panel has not said anything yet. */
    public const PENDING = 'pending';

    /**
     * Substrings that claim a status for a group, in matching order.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        self::COMPLETED => ['complet'],
        self::FAILED => ['cancel', 'error', 'fail', 'partial', 'refund'],
        self::PROCESSING => ['process', 'progress', 'active'],
        self::PENDING => [],
    ];

    /**
     * Every substring claimed by a non-pending group. `pending` is defined as
     * "matched none of these", so the filter needs the list.
     *
     * @return list<string>
     */
    public static function claimedPatterns(): array
    {
        return array_merge(
            self::GROUPS[self::COMPLETED],
            self::GROUPS[self::FAILED],
            self::GROUPS[self::PROCESSING],
        );
    }

    public static function fold(?string $status): string
    {
        $status = mb_strtolower(trim((string) $status));

        if ($status === '') {
            return self::PENDING;
        }

        foreach ([self::COMPLETED, self::FAILED, self::PROCESSING] as $group) {
            foreach (self::GROUPS[$group] as $pattern) {
                if (str_contains($status, $pattern)) {
                    return $group;
                }
            }
        }

        return self::PENDING;
    }

    public static function isValidGroup(?string $group): bool
    {
        return $group !== null && array_key_exists($group, self::GROUPS);
    }
}
