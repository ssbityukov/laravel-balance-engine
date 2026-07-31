<?php

namespace Bityukov\BalanceEngine;

use Illuminate\Support\ServiceProvider;

class BalanceEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/balance.php', 'balance');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/balance.php' => config_path('balance.php'),
        ], 'balance-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'balance-migrations');
    }
}
