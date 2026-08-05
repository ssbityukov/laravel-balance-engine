<?php

namespace Bityukov\BalanceEngine\Events;

use Bityukov\BalanceEngine\Ledger\Reservation;
use Bityukov\BalanceEngine\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class Reserved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly Transaction $transaction,
    ) {}
}
