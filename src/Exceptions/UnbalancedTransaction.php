<?php

namespace Bityukov\BalanceEngine\Exceptions;

/**
 * @phpstan-consistent-constructor
 */
class UnbalancedTransaction extends BalanceException
{
    public static function empty(): static
    {
        return new static('A ledger transaction needs at least one entry line.');
    }

    public static function for(int $sum): static
    {
        return new static(sprintf(
            'Ledger lines must sum to zero, got %d. This is a bug in the calling operation.',
            $sum,
        ));
    }
}
