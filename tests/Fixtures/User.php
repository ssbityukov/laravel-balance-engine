<?php

namespace Bityukov\BalanceEngine\Tests\Fixtures;

use Bityukov\BalanceEngine\Concerns\HasBalance;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasBalance;

    protected $table = 'users';

    protected $guarded = [];
}
