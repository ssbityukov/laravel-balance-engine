<?php

namespace Bityukov\BalanceEngine\Exceptions;

/**
 * @phpstan-consistent-constructor
 */
class ReservationNotFound extends BalanceException
{
    public static function for(string $uuid): static
    {
        return new static(sprintf(
            'No reservation with uuid [%s]. A reservation is a transaction of type [reserve]; '
            .'the uuid of any other transaction will not resolve to one.',
            $uuid,
        ));
    }
}
