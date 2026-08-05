<?php

namespace Bityukov\BalanceEngine\Tests;

use Bityukov\BalanceEngine\BalanceEngineServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Env;
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

    /**
     * DB_CONNECTION is honoured rather than hardcoded so the concurrency suite
     * can be pointed at MySQL or PostgreSQL. It defaults to Testbench's
     * in-memory sqlite, which is what the rest of the suite runs on.
     */
    protected function defineEnvironment($app): void
    {
        // Env::get rather than the env() helper: the helper is flagged outside
        // the config directory because it returns null once config is cached,
        // which cannot happen while bootstrapping a test.
        $connection = Env::get('DB_CONNECTION', 'testing');

        $app['config']->set('database.default', $connection);

        if ($connection !== 'testing') {
            $app['config']->set("database.connections.{$connection}.database", Env::get('DB_DATABASE', 'balance_engine_test'));
            $app['config']->set("database.connections.{$connection}.username", Env::get('DB_USERNAME', 'root'));
            $app['config']->set("database.connections.{$connection}.password", Env::get('DB_PASSWORD', ''));
        }

        $app['config']->set('auth.providers.users.model', Fixtures\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->createFixtureTables();
    }

    /**
     * Shared with ConcurrencyTestCase, which migrates differently but needs the
     * same fixtures.
     */
    protected function createFixtureTables(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        // Dropped first because a real database persists between tests, unlike
        // the in-memory sqlite the suite runs on by default.
        $schema->dropIfExists('users');
        $schema->dropIfExists('orders');

        $schema->create('users', function (Blueprint $table) {
            // The fixture's key type follows balance.owner_key_type, so the
            // table has to as well — see Fixtures\User for why a mismatch is
            // worse than useless.
            match (Fixtures\User::ownerKeyType()) {
                'uuid' => $table->uuid('id')->primary(),
                'ulid' => $table->ulid('id')->primary(),
                'string' => $table->string('id', 36)->primary(),
                default => $table->id(),
            };

            $table->string('name')->default('test');
            $table->timestamps();
        });

        // Always an integer key: reference_id is a plain bigint morph.
        $schema->create('orders', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
}
