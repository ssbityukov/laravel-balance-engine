<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Ledger\Reservation;

class ReservationAmountExceeded extends BalanceException
{
    public static function for(Reservation $reservation, int $requested): static
    {
        return new static(sprintf(
            'Reservation [%d] has %d remaining, %d requested.',
            $reservation->transaction->getKey(),
            $reservation->remaining(),
            $requested,
        ));
    }
}
