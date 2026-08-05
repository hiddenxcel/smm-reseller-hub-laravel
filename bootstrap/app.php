<?php

use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\BlockListedIps;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Outside the web group on purpose: webhooks carry no session or
            // CSRF token, and authenticate by signature instead.
            Route::middleware('api')->group(base_path('routes/webhooks.php'));

            // The super-admin console. Its own prefix and name space, so an
            // admin route can never collide with a reseller's.
            Route::middleware('web')
                ->prefix('hx-control')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            // Before anything else, and only on the web group: a gateway
            // confirming a payment arrives from its own address, and a block
            // placed for an unrelated reason must never stop money reaching a
            // reseller.
            BlockListedIps::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Applied to the whole web group, not to individual routes: an
            // impersonating admin holds a real tenant session, so any route
            // that session can reach is a route that has to be made read-only.
            // Listing routes to protect would mean a new one is unprotected by
            // default, which is the wrong way round for this.
            BlockDuringImpersonation::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdminIsActive::class,
        ]);

        // Where an unauthenticated request is sent back to. Without this every
        // guard shares the tenant login, so an admin whose session expired
        // would be dropped on the reseller sign-in page — and told to log in
        // somewhere that cannot let them back into the console.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('hx-control', 'hx-control/*')
                ? route('admin.login')
                : route('login'),
        );

        // And where an already-authenticated visitor is sent instead of a login
        // page: an admin lands back in the console, a reseller on their own
        // dashboard.
        $middleware->redirectUsersTo(
            fn (Request $request) => $request->is('hx-control', 'hx-control/*')
                ? route('admin.dashboard')
                : route('dashboard'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
