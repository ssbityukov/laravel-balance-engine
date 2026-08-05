<?php

namespace Bityukov\BalanceEngine\Tests\Feature;

use Bityukov\BalanceEngine\Concerns\HasBalance;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UuidOwner extends Model
{
    use HasBalance;
    use HasUuids;

    protected $table = 'uuid_owners';

    protected $guarded = [];
}

/**
 * The CI matrix only proves the owner_id column comes out the right type. This
 * proves money actually moves for an owner keyed by something other than an
 * integer.
 */
class UuidOwnerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('balance.owner_key_type', 'uuid');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::dropIfExists('uuid_owners');

        Schema::create('uuid_owners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
    }

    public function test_it_moves_money_for_a_uuid_keyed_owner(): void
    {
        $alice = UuidOwner::create();
        $bob = UuidOwner::create();

        Balance::deposit(to: $alice, amount: 10_000);
        Balance::transfer(from: $alice, to: $bob, amount: 4_000);

        $this->assertSame(6_000, $alice->balanceAmount());
        $this->assertSame(4_000, $bob->balanceAmount());
    }

    public function test_it_reserves_and_captures_for_a_uuid_keyed_owner(): void
    {
        $buyer = UuidOwner::create();
        $seller = UuidOwner::create();

        Balance::deposit(to: $buyer, amount: 10_000);

        $reservation = Balance::reserve(from: $buyer, amount: 3_000);
        $reservation->capture(to: $seller, amount: 1_000);

        // Available stays at 7000: the capture comes out of the hold account,
        // not out of what is left available.
        $this->assertSame(7_000, $buyer->balanceAmount());
        $this->assertSame(2_000, $buyer->balanceReserved());
        $this->assertSame(1_000, $seller->balanceAmount());
    }
}
