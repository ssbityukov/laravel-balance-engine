<?php

namespace Bityukov\BalanceEngine\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A reference target with an integer key, always.
 *
 * transactions.reference_id is a plain nullableMorphs and so is a bigint
 * regardless of balance.owner_key_type — the README says as much and tells
 * uuid-keyed applications to put the identifier in meta instead. Using an owner
 * model as a reference would break that rule the moment owners stop being
 * integer-keyed, and on PostgreSQL it fails loudly rather than silently.
 */
class Order extends Model
{
    protected $table = 'orders';

    protected $guarded = [];
}
