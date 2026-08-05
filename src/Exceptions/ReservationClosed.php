<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Ledger\Reservation;

/**
 * @phpstan-consistent-constructor
 */
class ReservationClosed extends BalanceException
{
    public static function for(Reservation $reservation): static
    {
        return new static(sprintf(
            'Reservation [%d] is %s and can no longer be captured or released.',
            $reservation->transaction->getKey(),
            $reservation->status()->value,
        ));
    }
}
