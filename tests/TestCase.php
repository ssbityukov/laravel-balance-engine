<?php

namespace Bityukov\BalanceEngine\Tests;

use Bityukov\BalanceEngine\BalanceEngineServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BalanceEngineServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', Fixtures\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app['db']->connection()->getSchemaBuilder()->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('test');
            $table->timestamps();
        });
    }
}
