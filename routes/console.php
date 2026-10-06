<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Needs `php artisan schedule:work` in development, or a single cron entry
| calling `schedule:run` every minute in production — see deploy/.
|
*/

/*
| Every five minutes: often enough that a reseller hears about a dead panel
| while it still matters, rarely enough that a shop with four panels makes
| under 1,200 requests a day against providers who are within their rights to
| rate-limit us.
|
| withoutOverlapping because a run where several panels are timing out at 30
| seconds each can outlast the interval, and overlapping runs would send a
| reseller the same "panel is down" email twice.
*/
Schedule::command('panels:check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Every two minutes: a customer whose order was cancelled is waiting for
| their money, and a panel is asked about a hundred orders in one call, so
| this is cheap. withoutOverlapping so a slow panel cannot make two runs
| refund the same order twice (the refund is idempotent anyway).
*/
Schedule::command('orders:sync')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Every minute: someone who has just paid is looking at their phone. The
| command spaces its questions out by the payment's age, so a quiet minute
| costs almost nothing.
*/
Schedule::command('payments:reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
| The public demo, put back the way visitors expect to find it.
|
| Registered only when an account is actually configured: this command
| deletes a tenant, and a schedule that exists on every install is one
| DEMO_EMAIL typo away from doing that to a real one.
|
| The account is read-only to begin with (LockDemoAccount), so this is
| clearing leftovers rather than undoing damage.
*/
if (config('demo.email')) {
    Schedule::command('demo:reset --force')
        ->everyMinute()
        ->when(fn () => now()->minute % max(1, (int) config('demo.reset_minutes')) === 0)
        ->withoutOverlapping()
        ->runInBackground();
}
