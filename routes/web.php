<?php

use App\Http\Controllers\LandingController;
use App\Http\Controllers\Onboarding\ConnectPanelController;
use App\Http\Controllers\Onboarding\ImportServicesController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', LandingController::class)->name('home');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    // Setup wizard. `onboarding` sends the reseller to whichever step they
    // still need, so it is safe to link to from anywhere.
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::get('/onboarding/{step}', [OnboardingController::class, 'show'])->name('onboarding.step');
    Route::post('/onboarding/panel', [ConnectPanelController::class, 'store'])->name('onboarding.panel.store');
    Route::post('/onboarding/services', [ImportServicesController::class, 'store'])->name('onboarding.services.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
