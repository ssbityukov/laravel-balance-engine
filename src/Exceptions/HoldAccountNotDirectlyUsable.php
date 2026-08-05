<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Models\Account;

/**
 * @phpstan-consistent-constructor
 */
class HoldAccountNotDirectlyUsable extends BalanceException
{
    public static function for(Account $account): static
    {
        return new static(sprintf(
            'Account [%d] is a hold account and cannot be used directly. Money reaches it only '
            .'through reserve(), and leaves it only through capture() or release(). Anything else '
            .'would make balanceReserved() report money that no reservation is holding.',
            $account->getKey(),
        ));
    }
}
