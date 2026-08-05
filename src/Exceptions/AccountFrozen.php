<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Models\Account;

/**
 * @phpstan-consistent-constructor
 */
class AccountFrozen extends BalanceException
{
    public function __construct(
        public readonly Account $account,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(Account $account): static
    {
        return new static($account, sprintf(
            'Account [%d] is frozen and cannot be debited%s.',
            $account->id,
            $account->frozen_reason ? ' (reason: '.$account->frozen_reason.')' : '',
        ));
    }
}
