<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Onboarding\ConnectPanelController;
use App\Http\Controllers\Onboarding\ConnectWhatsAppController;
use App\Http\Controllers\Onboarding\ImportServicesController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Onboarding\SetupPaymentsController;
use App\Http\Controllers\Onboarding\TestBotController;
use App\Http\Controllers\ProfileController;
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
    Route::post('/onboarding/payments', [SetupPaymentsController::class, 'store'])->name('onboarding.payments.store');
    Route::delete('/onboarding/payments/{gateway}', [SetupPaymentsController::class, 'destroy'])->name('onboarding.payments.destroy');
    Route::post('/onboarding/test/number', [TestBotController::class, 'storeNumber'])->name('onboarding.test.number.store');
    Route::delete('/onboarding/test/number/{phone}', [TestBotController::class, 'destroyNumber'])->name('onboarding.test.number.destroy');
    Route::post('/onboarding/test/go-live', [TestBotController::class, 'goLive'])->name('onboarding.test.golive');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
