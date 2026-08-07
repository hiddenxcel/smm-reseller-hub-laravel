<?php

namespace App\Services\Catalogue;

/** The bulk actions the services table offers, and how far they may reach. */
final class BulkServiceAction
{
    public const ACTIVATE = 'activate';

    public const HIDE = 'hide';

    public const PAUSE = 'pause';

    public const FEATURE = 'feature';

    public const UNFEATURE = 'unfeature';

    public const DELETE = 'delete';

    public const ALL = [
        self::ACTIVATE,
        self::HIDE,
        self::PAUSE,
        self::FEATURE,
        self::UNFEATURE,
        self::DELETE,
    ];

    /**
     * These are all local writes with no third-party call behind them, so the
     * cap is about keeping one request quick rather than about rate limits.
     */
    public const MAX_SELECTION = 1000;

    /**
     * Bulk pricing is capped lower: each row writes a history entry as well as
     * the price, and the reseller is shown a preview of every line first.
     */
    public const MAX_PRICING_SELECTION = 500;
}
