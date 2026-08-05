<?php

namespace Bityukov\BalanceEngine\Support;

use Bityukov\BalanceEngine\Exceptions\UnsupportedCurrency;

/**
 * The configured currency list is a whitelist, not documentation.
 *
 * Ledger records are immutable, so an account opened under a typo keeps that
 * currency forever and every entry written against it is stuck there too.
 * Rejecting the typo at the point of creation is the only cheap moment.
 */
final class Currency
{
    public static function assertSupported(string $currency): void
    {
        /** @var array<string, mixed> $configured */
        $configured = config('balance.currencies', []);

        if (! array_key_exists($currency, $configured)) {
            throw UnsupportedCurrency::for($currency, array_keys($configured));
        }
    }
}
