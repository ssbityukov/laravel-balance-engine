<?php

namespace Bityukov\BalanceEngine\Tests\Feature;

use Bityukov\BalanceEngine\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class OwnerKeyStringTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('balance.owner_key_type', 'string');
    }

    public function test_it_builds_string_owner_columns(): void
    {
        $this->assertStringContainsString(
            'char',
            Schema::getColumnType('balance_accounts', 'owner_id')
        );
    }
}
