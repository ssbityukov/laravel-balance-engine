<?php

namespace Bityukov\BalanceEngine\Support;

use Bityukov\BalanceEngine\Exceptions\CurrencyMismatch;
use Bityukov\BalanceEngine\Exceptions\UnresolvableOwner;
use Bityukov\BalanceEngine\Models\Account;
use Illuminate\Database\Eloquent\Model;

class AccountResolver
{
    /**
     * Turn whatever the caller passed — an Account or an owner model — into a
     * concrete available account.
     */
    public function resolve(Model $target, ?string $currency = null, ?string $name = null): Account
    {
        if ($target instanceof Account) {
            if ($currency !== null && $target->currency !== $currency) {
                throw CurrencyMismatch::between($target->currency, $currency);
            }

            return $target;
        }

        if (! method_exists($target, 'balanceAccount')) {
            throw UnresolvableOwner::for($target);
        }

        return $target->balanceAccount($name, $currency);
    }
}
