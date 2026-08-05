<?php

namespace Bityukov\BalanceEngine\Enums;

/**
 * Derived, never stored: there is no reservations table. The status is
 * computed from a reserve transaction and its capture/release children.
 */
enum ReservationStatus: string
{
    case Open = 'open';
    case Captured = 'captured';
    case Released = 'released';
    case Expired = 'expired';
}
