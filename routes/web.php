<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Onboarding\ConnectPanelController;
use App\Http\Controllers\Onboarding\ConnectWhatsAppController;
use App\Http\Controllers\Onboarding\ImportServicesController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Onboarding\RentNumberController;
use App\Http\Controllers\Onboarding\SkipStepController;
use App\Http\Controllers\Onboarding\SetupPaymentsController;
use App\Http\Controllers\Onboarding\TestBotController;
use App\Http\Controllers\OrderBotController;
use App\Http\Controllers\OrderBotGatewaysController;
use App\Http\Controllers\OrderBotInboxController;
use App\Http\Controllers\OrderBotProvidersController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SupportBotController;
use App\Http\Controllers\SupportBotInboxController;
use App\Http\Controllers\SupportBotTicketsController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

Route::get('/dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    // Setup wizard. `onboarding` sends the reseller to whichever step they
    // still need, so it is safe to link to from anywhere.
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::get('/onboarding/{step}', [OnboardingController::class, 'show'])->name('onboarding.step');
    Route::post('/onboarding/panel', [ConnectPanelController::class, 'store'])->name('onboarding.panel.store');
    Route::post('/onboarding/services', [ImportServicesController::class, 'store'])->name('onboarding.services.store');
    Route::post('/onboarding/whatsapp', [ConnectWhatsAppController::class, 'store'])->name('onboarding.whatsapp.store');
    Route::post('/onboarding/whatsapp/rent', [RentNumberController::class, 'store'])->name('onboarding.whatsapp.rent');
    Route::delete('/onboarding/whatsapp/rent/{rental}', [RentNumberController::class, 'destroy'])->name('onboarding.whatsapp.release');
    Route::post('/onboarding/payments', [SetupPaymentsController::class, 'store'])->name('onboarding.payments.store');
    Route::delete('/onboarding/payments/{gateway}', [SetupPaymentsController::class, 'destroy'])->name('onboarding.payments.destroy');
    Route::post('/onboarding/test/number', [TestBotController::class, 'storeNumber'])->name('onboarding.test.number.store');
    Route::delete('/onboarding/test/number/{phone}', [TestBotController::class, 'destroyNumber'])->name('onboarding.test.number.destroy');
    Route::post('/onboarding/test/go-live', [TestBotController::class, 'goLive'])->name('onboarding.test.golive');

    // "I'll come back to this." Skipping records a decision; it never marks
    // the step done, so the dashboard and go-live still ask for it.
    Route::post('/onboarding/skip/{step}', [SkipStepController::class, 'store'])->name('onboarding.skip');
    Route::delete('/onboarding/skip/{step}', [SkipStepController::class, 'destroy'])->name('onboarding.unskip');

    // Order bot. `{tab}` is real navigation — each tab is a URL a reseller can
    // link to — so it renders a page rather than answering JSON.
    // Declared before `{tab}` so these are never read as tab names.
    Route::get('/order-bot/inbox', OrderBotInboxController::class)->name('order-bot.inbox');
    Route::get('/order-bot/providers', [OrderBotProvidersController::class, 'index'])->name('order-bot.providers');
    Route::post('/order-bot/providers', [OrderBotProvidersController::class, 'store'])->name('order-bot.providers.store');
    Route::post('/order-bot/providers/{panel}/refresh', [OrderBotProvidersController::class, 'refresh'])->name('order-bot.providers.refresh');
    Route::delete('/order-bot/providers/{panel}', [OrderBotProvidersController::class, 'destroy'])->name('order-bot.providers.destroy');
    Route::get('/order-bot/gateways', [OrderBotGatewaysController::class, 'index'])->name('order-bot.gateways');
    Route::post('/order-bot/gateways', [OrderBotGatewaysController::class, 'store'])->name('order-bot.gateways.store');
    Route::post('/order-bot/gateways/{gateway}/toggle', [OrderBotGatewaysController::class, 'toggle'])->name('order-bot.gateways.toggle');
    Route::post('/order-bot/gateways/{gateway}/default', [OrderBotGatewaysController::class, 'setDefault'])->name('order-bot.gateways.default');
    Route::post('/order-bot/gateways/pesapal/register-ipn', [OrderBotGatewaysController::class, 'registerIpn'])->name('order-bot.gateways.register-ipn');
    Route::delete('/order-bot/gateways/{gateway}', [OrderBotGatewaysController::class, 'destroy'])->name('order-bot.gateways.destroy');
    Route::get('/order-bot/{tab?}', [OrderBotController::class, 'show'])
        ->whereIn('tab', ['setup', 'commands', 'logs', 'settings'])
        ->name('order-bot');
    Route::post('/order-bot/setup', [OrderBotController::class, 'updateSetup'])->name('order-bot.setup');
    Route::post('/order-bot/test-numbers', [OrderBotController::class, 'updateTestNumbers'])->name('order-bot.test-numbers');
    Route::post('/order-bot/commands', [OrderBotController::class, 'updateCommands'])->name('order-bot.commands');
    Route::post('/order-bot/settings', [OrderBotController::class, 'updateSettings'])->name('order-bot.settings');

    // Support bot. Same shape as the order bot above: fixed paths first, so
    // `/support-bot/inbox` is never read as a tab name.
    Route::get('/support-bot/inbox', [SupportBotInboxController::class, 'index'])->name('support-bot.inbox');
    Route::post('/support-bot/inbox/reply', [SupportBotInboxController::class, 'reply'])->name('support-bot.inbox.reply');
    Route::post('/support-bot/inbox/return', [SupportBotInboxController::class, 'returnToBot'])->name('support-bot.inbox.return');
    Route::get('/support-bot/tickets', [SupportBotTicketsController::class, 'index'])->name('support-bot.tickets');
    Route::get('/support-bot/tickets/{ticket}', [SupportBotTicketsController::class, 'show'])->name('support-bot.tickets.show');
    Route::post('/support-bot/tickets/{ticket}/reply', [SupportBotTicketsController::class, 'reply'])->name('support-bot.tickets.reply');
    Route::patch('/support-bot/tickets/{ticket}', [SupportBotTicketsController::class, 'update'])->name('support-bot.tickets.update');
    Route::post('/support-bot/rules', [SupportBotController::class, 'storeRule'])->name('support-bot.rules.store');
    Route::patch('/support-bot/rules/{rule}', [SupportBotController::class, 'updateRule'])->name('support-bot.rules.update');
    Route::delete('/support-bot/rules/{rule}', [SupportBotController::class, 'destroyRule'])->name('support-bot.rules.destroy');
    Route::post('/support-bot/templates', [SupportBotController::class, 'updateTemplate'])->name('support-bot.templates.update');
    Route::post('/support-bot/settings', [SupportBotController::class, 'updateSettings'])->name('support-bot.settings');
    Route::post('/support-bot/test-numbers', [SupportBotController::class, 'updateTestNumbers'])->name('support-bot.test-numbers');
    Route::get('/support-bot/{tab?}', [SupportBotController::class, 'show'])
        ->whereIn('tab', ['overview', 'rules', 'templates', 'settings'])
        ->name('support-bot');

    // Billing — the reseller paying US, as opposed to their customers paying
    // them. Runs on the platform's own merchant accounts.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');

    // Orders. Everything about the list lives in the query string, so a
    // filtered view is a shareable URL rather than throwaway React state.
    Route::get('/orders', [OrdersController::class, 'index'])->name('orders.index');
    Route::get('/orders/export', [OrdersController::class, 'export'])->name('orders.export');
    Route::get('/orders/matching-ids', [OrdersController::class, 'matchingIds'])->name('orders.matching-ids');
    Route::post('/orders/bulk', [OrdersController::class, 'bulk'])->name('orders.bulk');
    Route::post('/orders/{order}', [OrdersController::class, 'act'])->name('orders.act');

    // Customers. `{customer}/{tab}` is the slide-over fetching one tab at a
    // time — it is not navigation, so it answers JSON, not an Inertia page.
    Route::get('/customers', [CustomersController::class, 'index'])->name('customers.index');
    Route::get('/customers/export', [CustomersController::class, 'export'])->name('customers.export');
    Route::get('/customers/matching-ids', [CustomersController::class, 'matchingIds'])->name('customers.matching-ids');
    Route::post('/customers', [CustomersController::class, 'store'])->name('customers.store');
    Route::post('/customers/bulk', [CustomersController::class, 'bulk'])->name('customers.bulk');
    Route::get('/customers/{customer}/{tab?}', [CustomersController::class, 'show'])
        ->whereIn('tab', ['overview', 'orders', 'messages', 'wallet', 'tickets', 'activity'])
        ->name('customers.show');
    Route::post('/customers/{customer}', [CustomersController::class, 'act'])->name('customers.act');
    Route::patch('/customers/{customer}', [CustomersController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{customer}', [CustomersController::class, 'destroy'])->name('customers.destroy');

    // Services — the catalogue and its pricing. The literal segments are
    // declared before `{service}` so a route like /services/rules is never
    // read as a service id.
    Route::get('/services', [ServicesController::class, 'index'])->name('services.index');
    Route::get('/services/export', [ServicesController::class, 'export'])->name('services.export');
    Route::get('/services/matching-ids', [ServicesController::class, 'matchingIds'])->name('services.matching-ids');
    Route::get('/services/panels/{panel}/catalogue', [ServicesController::class, 'panelCatalogue'])->name('services.panel-catalogue');
    Route::post('/services', [ServicesController::class, 'store'])->name('services.store');
    Route::post('/services/bulk', [ServicesController::class, 'bulk'])->name('services.bulk');
    Route::post('/services/sync', [ServicesController::class, 'sync'])->name('services.sync');
    Route::post('/services/pricing/preview', [ServicesController::class, 'previewPricing'])->name('services.pricing.preview');
    Route::post('/services/pricing/apply', [ServicesController::class, 'applyPricing'])->name('services.pricing.apply');
    Route::post('/services/rules', [ServicesController::class, 'storeRule'])->name('services.rules.store');
    Route::post('/services/rules/apply', [ServicesController::class, 'applyRules'])->name('services.rules.apply');
    Route::patch('/services/rules/{rule}', [ServicesController::class, 'updateRule'])->name('services.rules.update');
    Route::delete('/services/rules/{rule}', [ServicesController::class, 'destroyRule'])->name('services.rules.destroy');
    Route::get('/services/{service}/{tab?}', [ServicesController::class, 'show'])
        ->whereIn('tab', ['overview', 'pricing', 'orders', 'logs', 'settings'])
        ->name('services.show');
    Route::post('/services/{service}', [ServicesController::class, 'act'])->name('services.act');
    Route::patch('/services/{service}', [ServicesController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServicesController::class, 'destroy'])->name('services.destroy');

    // Settings. The wizard is for getting set up once; this is for changing
    // what it put in place, so each tab is a URL a reseller can link to.
    Route::get('/settings/{tab?}', [SettingsController::class, 'show'])
        ->whereIn('tab', ['panel', 'services', 'whatsapp', 'payments'])
        ->name('settings');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
