<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Models\Account;

/**
 * @phpstan-consistent-constructor
 */
class CannotTransferToSelf extends BalanceException
{
    public static function for(Account $account): static
    {
        return new static(sprintf(
            'Cannot transfer account [%d] to itself.',
            $account->getKey(),
        ));
    }
}
