<?php

namespace Bityukov\BalanceEngine\Exceptions;

class InvalidAmount extends BalanceException
{
    public static function zero(): static
    {
        return new static('A ledger line amount cannot be zero.');
    }

    public static function notPositive(int $amount): static
    {
        return new static(sprintf('Amount must be a positive number of minor units, got %d.', $amount));
    }
}
