<?php

namespace App\Services\Api\Actions;

use App\Models\ApiKey;
use App\Models\BotOrder;
use App\Services\Api\ApiResult;
use App\Services\Api\OrderView;
use Illuminate\Http\Request;

/**
 * `action=status` with a comma-separated `orders` list — many at once.
 *
 * This exists so a caller polling fifty orders makes one request instead of
 * fifty, which is the difference between a client that fits inside its rate
 * limit and one that does not.
 *
 * The answer is keyed by the id that was asked for, and an id that does not
 * resolve gets an `{"error": ...}` entry rather than being dropped: a caller
 * matching the response back to its own records needs every key it sent.
 */
class OrdersAction implements ApiAction
{
    /**
     * A cap on ids per request. Without one, a single call could ask about
     * every order the reseller has ever taken.
     */
    private const MAX_IDS = 100;

    public function handle(Request $request, ApiKey $key): ApiResult
    {
        $raw = $request->input('orders');

        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return ApiResult::error('Orders is required');
        }

        $ids = collect(explode(',', (string) $raw))
            ->map(fn (string $id) => trim($id))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return ApiResult::error('Orders is required');
        }

        if ($ids->count() > self::MAX_IDS) {
            return ApiResult::error('No more than '.self::MAX_IDS.' orders per request');
        }

        $found = BotOrder::query()
            ->where('customer_id', $key->customer_id)
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        $answer = $ids->mapWithKeys(fn (string $id) => [
            $id => ($order = $found->get((int) $id)) === null
                ? ['error' => 'Incorrect order ID']
                : OrderView::of($order),
        ])->all();

        return ApiResult::ok($answer, ['asked' => $ids->count(), 'found' => $found->count()]);
    }
}
