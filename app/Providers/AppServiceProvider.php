<?php

namespace App\Providers;

use App\Services\Bots\BotHandlerFactory;
use App\Services\Bots\Order\OrderBotHandler;
use App\Services\Bots\Support\SupportBotHandler;
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
    }
}
