<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Concerns\HasBalance;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-consistent-constructor
 */
class UnresolvableOwner extends BalanceException
{
    public static function for(Model $model): static
    {
        return new static(sprintf(
            'Cannot resolve a balance account for [%s]. Add the [%s] trait to the model, or pass an Account instance.',
            $model::class,
            HasBalance::class,
        ));
    }
}
