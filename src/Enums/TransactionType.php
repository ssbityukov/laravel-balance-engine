<?php

namespace Bityukov\BalanceEngine\Enums;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case Transfer = 'transfer';
    case Reserve = 'reserve';
    case Capture = 'capture';
    case Release = 'release';
    case Reversal = 'reversal';

    /**
     * Reservation bookkeeping transactions cannot be reversed on their own: a
     * reservation's state is derived from the reserve transaction plus its
     * capture and release children, so reversing one link would leave the
     * chain describing a reservation that never happened that way.
     */
    public function isReversible(): bool
    {
        return ! in_array($this, [self::Reserve, self::Capture, self::Release], true);
    }
}
