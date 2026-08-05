<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Ledger\Reservation;

/**
 * @phpstan-consistent-constructor
 */
class ReservationExpired extends BalanceException
{
    public static function for(Reservation $reservation): static
    {
        return new static(sprintf(
            'Reservation [%d] expired at %s and cannot be captured. Release it instead.',
            $reservation->transaction->getKey(),
            $reservation->expiresAt()?->toDateTimeString() ?? 'unknown',
        ));
    }
}
