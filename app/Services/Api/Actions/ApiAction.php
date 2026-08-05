<?php

namespace App\Services\Api\Actions;

use App\Models\ApiKey;
use App\Services\Api\ApiResult;
use Illuminate\Http\Request;

/**
 * One `action` value from the SMM API v2.
 *
 * The key is passed in rather than read from the session because the customer
 * it belongs to is the subject of most calls — whose wallet, whose orders —
 * and taking it from the argument makes that impossible to forget.
 */
interface ApiAction
{
    public function handle(Request $request, ApiKey $key): ApiResult;
}
