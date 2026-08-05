<?php

use App\Http\Controllers\Api\ApiV2Controller;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The reseller API
|--------------------------------------------------------------------------
|
| Called by a reseller's customers — their own shop's checkout, a script, a
| child panel — never by a browser here. No session and no CSRF token; the
| `key` field in the body is the credential.
|
| One route, on purpose. The SMM API v2 is a single endpoint that switches on
| an `action` field, and every client library written against any panel
| expects exactly that. Splitting it into REST resources would be tidier and
| would make this API unusable by the tools it exists to serve.
|
| Like the webhook URLs, this path ends up pasted into other people's code and
| is effectively permanent.
|
*/

Route::post('v2', ApiV2Controller::class)
    ->middleware(AuthenticateApiKey::class)
    ->name('api.v2');
