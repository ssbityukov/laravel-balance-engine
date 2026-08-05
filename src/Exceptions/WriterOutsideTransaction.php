<?php

namespace Bityukov\BalanceEngine\Exceptions;

class WriterOutsideTransaction extends BalanceException
{
    public static function make(): static
    {
        return new static(
            'TransactionWriter must run inside a database transaction. '
            .'Call it through BalanceManager, never directly.'
        );
    }
}
