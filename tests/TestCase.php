<?php

namespace Bityukov\BalanceEngine\Tests;

use Bityukov\BalanceEngine\BalanceEngineServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Not diagnostics — an invariant of the test environment. The
     * WriterOutsideTransaction guard and every ShouldDispatchAfterCommit
     * listener are untestable through a savepoint. If this fails, a
     * transactional RefreshDatabase has been added: remove it rather than
     * working around it with rollBack().
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            0,
            DB::transactionLevel(),
            'Package tests must not run inside a wrapping transaction: '
            .'TransactionWriter and after-commit events cannot be tested through a savepoint.'
        );
    }

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
