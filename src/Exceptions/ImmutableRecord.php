<?php

namespace Bityukov\BalanceEngine\Exceptions;

/**
 * @phpstan-consistent-constructor
 */
class ImmutableRecord extends BalanceException
{
    public static function for(string $model, string $action): static
    {
        return new static(sprintf(
            'Ledger records are immutable: cannot %s a [%s]. Use Balance::reverse() instead.',
            $action,
            $model,
        ));
    }
}
