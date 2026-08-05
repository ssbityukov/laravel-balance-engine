<?php

namespace Bityukov\BalanceEngine\Events;

use Bityukov\BalanceEngine\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class TransactionReversed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Transaction $original,
        public readonly Transaction $reversal,
    ) {}
}
