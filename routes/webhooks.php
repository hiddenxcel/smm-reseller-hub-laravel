<?php

use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook routes
|--------------------------------------------------------------------------
|
| Called by Meta and the payment gateways, never by a browser: no session,
| no CSRF token. Each one authenticates itself by signature instead.
|
| These URLs are registered with third parties and pasted into resellers'
| Meta app settings, so they are effectively permanent — changing a path
| means every reseller has to update their configuration.
|
*/

Route::prefix('webhooks')->group(function () {
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->name('webhooks.whatsapp.verify');

    Route::post('whatsapp', [WhatsAppWebhookController::class, 'handle'])
        ->name('webhooks.whatsapp');
});
