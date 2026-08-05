<?php

namespace Bityukov\BalanceEngine\Tests;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class ConcurrencyTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for concurrency tests.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'SQLite has no row-level locking: lockForUpdate() is a no-op, so concurrency cannot be tested. '
                .'Run this suite against MySQL or PostgreSQL.'
            );
        }

        $this->assertSame(0, DB::transactionLevel(), 'Concurrency tests must not run inside a transaction.');
    }

    /**
     * Fresh migrations without a wrapping transaction: children must see the data.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', ['--database' => config('database.default')]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate', ['--database' => config('database.default')]);

        // Same fixtures as the rest of the suite, so the owner key type is
        // honoured here too rather than hardcoded to an integer.
        $this->createFixtureTables();
    }

    /**
     * Run $child in $times separate processes and collect their exit codes.
     *
     * @return array<int, int>
     */
    protected function fork(int $times, Closure $child): array
    {
        $pids = [];

        for ($i = 0; $i < $times; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                // Child: never share the parent's database socket. Two processes
                // writing down one socket corrupt the protocol.
                DB::purge();

                $code = 0;

                try {
                    $code = $child($i) ?? 0;
                } catch (Throwable) {
                    $code = 99;
                }

                // exit() rather than return: the child must not continue the test run.
                exit($code);
            }

            $pids[] = $pid;
        }

        $codes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);

            $codes[] = pcntl_wexitstatus($status);
        }

        return $codes;
    }
}
