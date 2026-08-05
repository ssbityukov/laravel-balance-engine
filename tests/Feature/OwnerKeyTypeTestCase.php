<?php

namespace Bityukov\BalanceEngine\Tests\Feature;

use Bityukov\BalanceEngine\Support\OwnerKey;
use Bityukov\BalanceEngine\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One shared body for every owner_key_type variant. defineEnvironment() has to
 * override the config before migrations run, which a Pest closure cannot do —
 * hence plain PHPUnit classes.
 */
abstract class OwnerKeyTypeTestCase extends TestCase
{
    abstract protected function keyType(): string;

    /**
     * The column type OwnerKey should declare, as Blueprint names it, plus its
     * length where the type carries one.
     *
     * @return array{0: string, 1: int|null}
     */
    abstract protected function expectedColumnType(): array;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('balance.owner_key_type', $this->keyType());
    }

    /**
     * The built database cannot discriminate the variants: Laravel's SQLite
     * grammar emits a bare `varchar` for uuid, ulid and string alike, dropping
     * the length that is the only difference between them. Assert on the
     * column definition OwnerKey produces instead — it is both driver-agnostic
     * and the actual unit under test.
     */
    public function test_it_declares_the_configured_owner_column_type(): void
    {
        [$type, $length] = $this->expectedColumnType();

        $column = $this->ownerIdColumn();

        $this->assertSame($type, $column->get('type'));
        $this->assertSame($length, $column->get('length'));
    }

    public function test_it_migrates_the_owner_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('balance_accounts', ['owner_type', 'owner_id']));
    }

    protected function ownerIdColumn(): ColumnDefinition
    {
        $table = new Blueprint(DB::connection(), 'balance_accounts');

        OwnerKey::morphs($table, 'owner');

        foreach ($table->getColumns() as $column) {
            if ($column->get('name') === 'owner_id') {
                return $column;
            }
        }

        $this->fail('OwnerKey::morphs() did not declare an [owner_id] column.');
    }
}
