<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Models\Account;

/**
 * @phpstan-consistent-constructor
 */
class InsufficientFunds extends BalanceException
{
    public function __construct(
        public readonly Account $account,
        public readonly int $requested,
        public readonly int $available,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(Account $account, int $requested, int $available): static
    {
        return new static($account, $requested, $available, sprintf(
            'Account [%d] has %d %s available, %d requested.',
            $account->id,
            $available,
            $account->currency,
            $requested,
        ));
    }
}
