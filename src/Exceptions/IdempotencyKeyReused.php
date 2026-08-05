<?php

namespace Bityukov\BalanceEngine\Exceptions;

/**
 * @phpstan-consistent-constructor
 */
class IdempotencyKeyReused extends BalanceException
{
    public static function for(string $key): static
    {
        return new static(sprintf(
            'Idempotency key [%s] was already used for a different operation. '
            .'Returning the stored transaction would hide a bug in the caller, so this throws instead.',
            $key,
        ));
    }
}
