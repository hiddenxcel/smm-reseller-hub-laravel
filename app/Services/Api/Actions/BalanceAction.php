<?php

namespace App\Services\Api\Actions;

use App\Models\ApiKey;
use App\Services\Api\ApiResult;
use Illuminate\Http\Request;

/**
 * `action=balance` — what is left in the caller's wallet.
 *
 * This is the customer's wallet, the same one the WhatsApp bot spends. There
 * is no separate API balance to top up, which is the whole point of tying a
 * key to a customer rather than inventing a second account for them.
 *
 * Topping up stays where it already works: the customer pays through the bot,
 * and the API spends what is there.
 */
class BalanceAction implements ApiAction
{
    public function handle(Request $request, ApiKey $key): ApiResult
    {
        // The relation was loaded by the middleware and is this request's own
        // read — no refresh, which would only re-query the same row.
        return ApiResult::ok([
            'balance' => (string) $key->customer->balance,
            'currency' => 'USD',
        ]);
    }
}
