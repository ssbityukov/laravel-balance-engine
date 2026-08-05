<?php

namespace Bityukov\BalanceEngine\Console;

use Bityukov\BalanceEngine\BalanceManager;
use Bityukov\BalanceEngine\Ledger\Reservation;
use Bityukov\BalanceEngine\Ledger\ReservationQuery;
use Illuminate\Console\Command;

class ExpireReservationsCommand extends Command
{
    protected $signature = 'balance:expire-reservations';

    protected $description = 'Release the remainder of every reservation whose expiry has passed.';

    public function handle(BalanceManager $manager, ReservationQuery $reservations): int
    {
        $expired = 0;

        $reservations->expired()->each(function (Reservation $reservation) use ($manager, &$expired): void {
            $manager->expireReservation($reservation);

            $expired++;
        });

        $this->info("Expired {$expired} reservation(s).");

        return self::SUCCESS;
    }
}
