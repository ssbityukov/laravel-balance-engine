<?php

namespace Bityukov\BalanceEngine;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class BalanceEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/balance.php', 'balance');

        $this->app->singleton(BalanceManager::class);
        $this->app->alias(BalanceManager::class, 'balance');
    }

    public function boot(): void
    {
        $this->warnAboutMissingRowLocks();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/balance.php' => config_path('balance.php'),
        ], 'balance-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'balance-migrations');

        $this->commands([
            Console\ExpireReservationsCommand::class,
            Console\RebuildCommand::class,
            Console\VerifyCommand::class,
        ]);
    }

    /**
     * SQLite has no row-level locking: lockForUpdate() is a no-op there, so the
     * package cannot protect against concurrent writes. Fine for tests, unsafe
     * in production.
     *
     * The driver is read from config rather than through
     * DB::connection()->getDriverName() so that booting the application does
     * not open a database connection.
     */
    protected function warnAboutMissingRowLocks(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            return;
        }

        Log::warning(
            '[balance-engine] SQLite does not support row locks. '
            .'Concurrent balance operations are NOT safe on this driver. '
            .'Use MySQL or PostgreSQL in production.'
        );
    }
}
