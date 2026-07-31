<?php

namespace Bityukov\BalanceEngine\Tests\Feature;

use Bityukov\BalanceEngine\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class OwnerKeyUuidTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('balance.owner_key_type', 'uuid');
    }

    public function test_it_builds_uuid_owner_columns(): void
    {
        $this->assertStringContainsString(
            'char',
            Schema::getColumnType('balance_accounts', 'owner_id')
        );
    }
}
