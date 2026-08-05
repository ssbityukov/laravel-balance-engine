<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Models\Transaction;

class TransactionNotReversible extends BalanceException
{
    public static function for(Transaction $transaction): static
    {
        return new static(sprintf(
            'Transactions of type [%s] cannot be reversed. A reversal is not part of the reserve '
            .'chain, so it would move money on or off the hold account without the reservation '
            .'seeing it, and the derived remaining would stop matching the hold balance. '
            .'Use capture() or release() on the reservation instead.',
            $transaction->type->value,
        ));
    }
}
