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
