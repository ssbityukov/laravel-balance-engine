<?php

namespace Bityukov\BalanceEngine\Exceptions;

/**
 * @phpstan-consistent-constructor
 */
class UnsupportedCurrency extends BalanceException
{
    /**
     * @param  array<int, string>  $supported
     */
    public static function for(string $currency, array $supported): static
    {
        return new static(sprintf(
            'Currency [%s] is not configured. Add it to balance.currencies, or use one of: %s. '
            .'Ledger records are immutable, so an account opened in the wrong currency stays that way.',
            $currency,
            implode(', ', $supported),
        ));
    }
}
