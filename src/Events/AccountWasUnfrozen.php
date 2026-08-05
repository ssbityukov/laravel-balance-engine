<?php

namespace Bityukov\BalanceEngine\Events;

use Bityukov\BalanceEngine\Models\Account;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class AccountWasUnfrozen implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Account $account,
    ) {}
}
