<?php

namespace App\Services\Api\Actions;

use App\Models\ApiKey;
use App\Models\BotService;
use App\Services\Api\ApiResult;
use Illuminate\Http\Request;

/**
 * `action=services` — the reseller's catalogue, at the reseller's prices.
 *
 * Only `active` services are listed. A paused one is deliberately absent
 * rather than listed-and-refused: the caller is code, and code that reads a
 * catalogue will order from it.
 *
 * Prices are the reseller's own (my_price), never cost_price — what the
 * reseller pays their panel is not the caller's business.
 */
class ServicesAction implements ApiAction
{
    public function handle(Request $request, ApiKey $key): ApiResult
    {
        $services = BotService::query()
            ->where('status', BotService::ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // The v2 convention is a bare array of objects, with every value a
        // string — including the numeric ones. Clients written against other
        // panels parse it that way, so matching it exactly is the point.
        $listing = $services->map(fn (BotService $service) => [
            'service' => (string) $service->id,
            'name' => $service->name,
            'type' => 'Default',
            'category' => $service->category ?? $service->platform,
            'rate' => (string) $service->my_price,
            'min' => (string) $service->min_quantity,
            'max' => (string) $service->max_quantity,
            // No panel this app talks to reports either, and saying "true"
            // for a guarantee that does not exist would be a promise the
            // reseller has to keep.
            'refill' => false,
            'cancel' => false,
        ])->all();

        return ApiResult::ok($listing, ['count' => count($listing)]);
    }
}
