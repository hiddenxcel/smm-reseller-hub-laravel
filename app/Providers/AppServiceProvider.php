<?php

namespace App\Providers;

use App\Services\Bots\BotHandlerFactory;
use App\Services\Bots\Order\OrderBotHandler;
use App\Services\Bots\Support\SupportBotHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The router dispatches by bot name, so registering the handlers here
        // keeps it from depending on the classes directly — which is what lets
        // its tests swap in fakes.
        $this->app->singleton(BotHandlerFactory::class, function () {
            $factory = new BotHandlerFactory;

            $factory->register('order', OrderBotHandler::class);
            $factory->register('support', SupportBotHandler::class);

            return $factory;
        });
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // The practice chat is open to anyone and every message runs a
        // transaction. A person chatting sends a few a minute; this leaves
        // room for that and none for a script.
        RateLimiter::for('try-bot', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip()),
            Limit::perDay(400)->by($request->ip()),
        ]);
    }
}
