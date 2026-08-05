<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Models\Account;

class CannotReserveSystemAccount extends BalanceException
{
    public static function for(Account $account): static
    {
        return new static(sprintf(
            'Account [%s] is a system account and has no owner, so it cannot hold reservations.',
            $account->code ?? $account->getKey(),
        ));
    }
}
