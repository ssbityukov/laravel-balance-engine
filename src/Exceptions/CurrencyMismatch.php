<?php

namespace Bityukov\BalanceEngine\Exceptions;

/**
 * @phpstan-consistent-constructor
 */
class CurrencyMismatch extends BalanceException
{
    public static function between(string $expected, string $actual): static
    {
        return new static(sprintf(
            'All lines of a transaction must share one currency, got [%s] and [%s]. Cross-currency transactions are not supported.',
            $expected,
            $actual,
        ));
    }
}
