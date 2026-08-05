<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Models\Transaction;

/**
 * @phpstan-consistent-constructor
 */
class TransactionAlreadyReversed extends BalanceException
{
    public static function for(Transaction $transaction): static
    {
        return new static(sprintf(
            'Transaction [%s] has already been reversed.',
            $transaction->uuid,
        ));
    }
}
