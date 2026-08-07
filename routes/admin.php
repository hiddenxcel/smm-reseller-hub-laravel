<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminSessionController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\AnnouncementsController;
use App\Http\Controllers\Admin\BotsController;
use App\Http\Controllers\Admin\CatalogueController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\PlansController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SubscriptionsController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\TenantsController;
use App\Http\Controllers\Admin\TicketsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super-admin console
|--------------------------------------------------------------------------
|
| Everything here runs on the `superadmin` guard, which is a different session
| from a reseller's — see config/auth.php. The path matches the old platform's
| /hx-control.
|
| Route names are prefixed `admin.` by the group in bootstrap, so nothing here
| can collide with a tenant route of the same name.
|
*/

Route::middleware('guest:superadmin')->group(function () {
    Route::get('login', [AdminSessionController::class, 'create'])->name('login');
    Route::post('login', [AdminSessionController::class, 'store']);
});

// The two ways out of an impersonation, both reachable from inside one.
//
// They sit outside the `admin` middleware deliberately: that guard bounces the
// console away while a visit is open, so a logout placed behind it would be
// refused at exactly the moment an admin needs it — leaving a live tenant
// session with nobody accountable for it and a visit that never closes.
Route::middleware('auth:superadmin')->group(function () {
    Route::post('impersonate/stop', [ImpersonationController::class, 'destroy'])
        ->name('impersonate.stop');

    Route::post('logout', [AdminSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth:superadmin', 'admin'])->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    // Tenants. Literal segments before `{tenant}` so `/tenants/export` is never
    // read as an id, matching how the tenant-facing screens are routed.
    Route::get('tenants', [TenantsController::class, 'index'])->name('tenants.index');
    Route::get('tenants/export', [TenantsController::class, 'export'])->name('tenants.export');
    Route::get('tenants/{tenant}/{tab?}', [TenantsController::class, 'show'])
        ->whereIn('tab', ['overview', 'subscriptions', 'orders', 'customers', 'payments', 'panels', 'numbers', 'activity'])
        ->whereNumber('tenant')
        ->name('tenants.show');
    Route::patch('tenants/{tenant}', [TenantsController::class, 'update'])->whereNumber('tenant')->name('tenants.update');

    // Declared before the catch-all `{action}` below so it keeps its own
    // controller even if `impersonate` is ever added to that list.
    Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'store'])
        ->whereNumber('tenant')
        ->name('impersonate.start');

    Route::post('tenants/{tenant}/{action}', [TenantsController::class, 'act'])
        ->whereIn('action', ['suspend', 'activate', 'credit', 'password'])
        ->whereNumber('tenant')
        ->name('tenants.act');

    // Plans — the price list. No delete route: retiring keeps the row that the
    // payment and subscription history points at.
    Route::get('plans', [PlansController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlansController::class, 'store'])->name('plans.store');
    Route::patch('plans/{plan}', [PlansController::class, 'update'])->name('plans.update');
    Route::post('plans/{plan}/{action}', [PlansController::class, 'act'])
        ->whereIn('action', ['retire', 'restore'])
        ->name('plans.act');

    Route::get('subscriptions', [SubscriptionsController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions/{subscription}/{action}', [SubscriptionsController::class, 'act'])
        ->whereIn('action', ['extend', 'cancel', 'reinstate', 'auto-renew'])
        ->name('subscriptions.act');

    Route::get('payments', [PaymentsController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentsController::class, 'show'])
        ->whereNumber('payment')
        ->name('payments.show');
    Route::post('payments/{payment}/{action}', [PaymentsController::class, 'act'])
        ->whereIn('action', ['confirm', 'fail', 'reapply'])
        ->whereNumber('payment')
        ->name('payments.act');

    // Products. One controller serves both bots — the screens are the same
    // shape, and the support bot simply has a ticket queue the order bot lacks.
    Route::post('bots/templates', [BotsController::class, 'saveTemplate'])->name('bots.templates');
    Route::get('bots/{bot}/{tab?}', [BotsController::class, 'show'])
        ->whereIn('bot', ['order', 'support'])
        ->whereIn('tab', ['overview', 'templates', 'defaults'])
        ->name('bots');

    Route::get('catalogue', [CatalogueController::class, 'index'])->name('catalogue');

    // Analytics.
    Route::get('reports', [ReportsController::class, 'index'])->name('reports');

    Route::get('announcements', [AnnouncementsController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [AnnouncementsController::class, 'store'])->name('announcements.store');
    Route::patch('announcements/{announcement}', [AnnouncementsController::class, 'update'])
        ->name('announcements.update');
    Route::post('announcements/{announcement}/{action}', [AnnouncementsController::class, 'act'])
        ->whereIn('action', ['publish', 'unpublish', 'delete'])
        ->name('announcements.act');

    // Tickets are read-only here: replying would put the platform's words into
    // a reseller's WhatsApp thread under their business name.
    Route::get('tickets', [TicketsController::class, 'index'])->name('tickets.index');
    Route::get('tickets/{ticket}', [TicketsController::class, 'show'])
        ->whereNumber('ticket')
        ->name('tickets.show');

    // Support, the other direction: resellers asking us. Writable, because
    // these are addressed to the platform rather than to a reseller's customer.
    Route::get('support', [SupportController::class, 'index'])->name('support.index');
    Route::get('support/{ticket}', [SupportController::class, 'show'])
        ->whereNumber('ticket')
        ->name('support.show');
    Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])
        ->whereNumber('ticket')
        ->name('support.reply');
    Route::post('support/{ticket}/{action}', [SupportController::class, 'act'])
        ->whereIn('action', ['resolve', 'close', 'reopen', 'priority'])
        ->whereNumber('ticket')
        ->name('support.act');

    // System. Owner-only inside the controllers — creating admins and editing
    // settings is how someone would grant themselves more than they have.
    Route::get('settings', [SystemController::class, 'settings'])->name('settings');
    Route::post('settings', [SystemController::class, 'saveSettings'])->name('settings.save');

    Route::get('admins', [AdminUsersController::class, 'index'])->name('admins.index');
    Route::post('admins', [AdminUsersController::class, 'store'])->name('admins.store');
    Route::patch('admins/{admin}', [AdminUsersController::class, 'update'])->name('admins.update');
    Route::post('admins/{admin}/{action}', [AdminUsersController::class, 'act'])
        ->whereIn('action', ['disable', 'enable', 'password'])
        ->name('admins.act');

    Route::get('security', [SystemController::class, 'security'])->name('security');
    Route::post('security/block', [SystemController::class, 'blockIp'])->name('security.block');
    Route::delete('security/block/{blockedIp}', [SystemController::class, 'unblockIp'])
        ->name('security.unblock');

    // No route writes or deletes an activity row: a trail somebody can quietly
    // tidy is not a trail.
    Route::get('audit', [SystemController::class, 'audit'])->name('audit');

    // Backups can be taken, downloaded and deleted here. Restoring is a shell
    // command on purpose — see the Backups service.
    Route::get('backups', [SystemController::class, 'backups'])->name('backups');
    Route::post('backups', [SystemController::class, 'createBackup'])->name('backups.create');
    Route::get('backups/{file}', [SystemController::class, 'downloadBackup'])->name('backups.download');
    Route::delete('backups/{file}', [SystemController::class, 'deleteBackup'])->name('backups.delete');
});
