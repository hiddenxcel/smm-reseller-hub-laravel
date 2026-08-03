<?php

use App\Http\Controllers\Webhooks\BillingWebhookController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
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

    // One URL per gateway, shared by every reseller. Which reseller a payment
    // belongs to comes from our reference in the payload, not the URL.
    Route::post('payment/{gateway}', PaymentWebhookController::class)
        ->name('webhooks.payment');

    // Subscriptions, on a separate path from the one above. Same gateways,
    // opposite direction: this is a reseller paying the platform, on the
    // platform's own merchant accounts, and it grants subscriptions rather
    // than crediting a customer's wallet. One URL deciding between two
    // unrelated payment tables is exactly how money lands in the wrong place.
    Route::post('billing/{gateway}', BillingWebhookController::class)
        ->name('webhooks.billing');
});
