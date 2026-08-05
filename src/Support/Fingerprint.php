<?php

namespace Bityukov\BalanceEngine\Support;

use Bityukov\BalanceEngine\Enums\TransactionType;

/**
 * Deterministic hash of an operation's shape, used to detect an idempotency
 * key being reused for a different operation.
 */
final class Fingerprint
{
    /**
     * @param  array<int, int>  $accountIds
     */
    public static function make(TransactionType $type, array $accountIds, int $amount, string $currency): string
    {
        sort($accountIds);

        return hash('sha256', implode('|', [
            $type->value,
            implode(',', $accountIds),
            $amount,
            $currency,
        ]));
    }
}
