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
        //
    }
}
