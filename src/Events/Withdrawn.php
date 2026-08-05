<?php

namespace Bityukov\BalanceEngine\Events;

use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class Withdrawn implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly Account $account,
        public readonly int $amount,
    ) {}
}
