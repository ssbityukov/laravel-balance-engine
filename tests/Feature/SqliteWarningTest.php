<?php

namespace Bityukov\BalanceEngine\Tests\Feature;

use Bityukov\BalanceEngine\BalanceEngineServiceProvider;
use Bityukov\BalanceEngine\Tests\TestCase;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;

class SqliteWarningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test is about the sqlite driver specifically.');
        }

        // The provider warns once per process, so without clearing the flag this
        // test would pass or fail depending on what ran before it.
        $flag = new ReflectionProperty(BalanceEngineServiceProvider::class, 'warnedAboutRowLocks');
        $flag->setValue(null, false);
    }

    public function test_it_warns_when_running_on_sqlite_in_production(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'row locks'));

        $this->inProduction(fn () => $this->app->register(BalanceEngineServiceProvider::class, true));
    }

    public function test_it_stays_quiet_outside_production(): void
    {
        Log::shouldReceive('warning')->never();

        $this->app->register(BalanceEngineServiceProvider::class, true);
    }

    /**
     * The production environment is borrowed for the duration of one call and
     * then handed back, for two separate reasons.
     *
     * Setting config('app.env') alone would not work: Application::environment()
     * answers from $app['env'], bootstrapped once at startup, so a later config
     * write leaves the application still reporting "testing".
     *
     * And $app['env'] must not still say production when migrations run. Both
     * the migration in setUp and the rollback in tearDown stop to ask
     * "Application In Production. Do you really wish to run this command?",
     * which fails the test on an unexpected prompt. The environment only needs
     * to look like production while the provider boots.
     */
    protected function inProduction(Closure $callback): void
    {
        $original = $this->app['env'];

        $this->app['config']->set('app.env', 'production');
        $this->app['env'] = 'production';

        try {
            $callback();
        } finally {
            $this->app['env'] = $original;
            $this->app['config']->set('app.env', $original);
        }
    }
}
