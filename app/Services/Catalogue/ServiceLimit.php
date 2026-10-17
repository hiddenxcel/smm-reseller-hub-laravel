<?php

namespace App\Services\Catalogue;

use App\Models\BotService;
use Illuminate\Support\Facades\DB;

/**
 * At most ten services a customer can see in any one category.
 *
 * WhatsApp draws no more than ten rows in a list, so the bot cannot show an
 * eleventh service — it was silently cut off, and the reseller had no way to
 * know. The limit is therefore held here, where services are added, rather than
 * discovered by a customer who cannot find what they were sold.
 *
 * A category is a platform and a category together: ten Instagram Likes, ten
 * Instagram Followers and ten TikTok Likes are three full categories, not one.
 * Only services the customer can see count; a hidden service takes no row, so
 * hiding one makes room for another.
 */
final class ServiceLimit
{
    public const MAX_PER_CATEGORY = 10;

    /** How many services a customer can see in this category right now. */
    public static function listed(int $tenantId, ?string $platform, ?string $category, ?int $exceptId = null): int
    {
        return BotService::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', BotService::HIDDEN)
            ->whereRaw('lower(trim(coalesce(platform, \'\'))) = ?', [self::normal($platform)])
            ->whereRaw('lower(trim(coalesce(category, \'\'))) = ?', [self::normal($category)])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->count();
    }

    /** Whether one more service can be shown in this category. */
    public static function hasRoom(int $tenantId, ?string $platform, ?string $category, ?int $exceptId = null): bool
    {
        return self::listed($tenantId, $platform, $category, $exceptId) < self::MAX_PER_CATEGORY;
    }

    /** What to tell a reseller who is over it. */
    public static function message(?string $platform, ?string $category): string
    {
        $name = trim(trim((string) $platform).' '.trim((string) $category)) ?: 'this category';

        return 'WhatsApp shows only '.self::MAX_PER_CATEGORY." services in a list, so {$name} is full. "
            .'Delete or hide one of its services first, then add this one.';
    }

    /** Free rows per category for one reseller, for the page to show. */
    public static function usage(int $tenantId): array
    {
        return DB::table('bot_services')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', BotService::HIDDEN)
            ->selectRaw('lower(trim(coalesce(platform, \'\'))) as platform, lower(trim(coalesce(category, \'\'))) as category, count(*) as total')
            ->groupByRaw('lower(trim(coalesce(platform, \'\'))), lower(trim(coalesce(category, \'\')))')
            ->get()
            ->map(fn ($row) => [
                'platform' => $row->platform,
                'category' => $row->category,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private static function normal(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
