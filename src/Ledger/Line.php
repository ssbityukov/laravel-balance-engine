<?php

namespace Bityukov\BalanceEngine\Ledger;

use Bityukov\BalanceEngine\Models\Account;

/**
 * One side of a ledger transaction: a locked account and a signed amount
 * in minor units. Negative debits, positive credits.
 */
final readonly class Line
{
    public function __construct(
        public Account $account,
        public int $amount,
    ) {}
}
