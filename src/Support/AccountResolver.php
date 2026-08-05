<?php

namespace Bityukov\BalanceEngine\Support;

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Exceptions\CannotReserveSystemAccount;
use Bityukov\BalanceEngine\Exceptions\CurrencyMismatch;
use Bityukov\BalanceEngine\Exceptions\HoldAccountNotDirectlyUsable;
use Bityukov\BalanceEngine\Exceptions\UnresolvableOwner;
use Bityukov\BalanceEngine\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class AccountResolver
{
    /**
     * Turn whatever the caller passed — an Account or an owner model — into a
     * concrete available account.
     */
    public function resolve(Model $target, ?string $currency = null, ?string $name = null): Account
    {
        if ($currency !== null) {
            Currency::assertSupported($currency);
        }

        if ($target instanceof Account) {
            // Hold accounts are reachable only through reserve, capture and
            // release, none of which come through here for the hold side. Any
            // other operation touching one would put money on it that no
            // reservation is holding, and balanceReserved() would report it.
            if ($target->purpose === AccountPurpose::Hold) {
                throw HoldAccountNotDirectlyUsable::for($target);
            }

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

    /**
     * The hold account paired with an available one: same owner, same name,
     * same currency. Reserved money lives here as a real balance rather than
     * as a column on the account it came from.
     */
    public function hold(Account $account): Account
    {
        if ($account->owner_id === null) {
            throw CannotReserveSystemAccount::for($account);
        }

        $attributes = [
            'owner_type' => $account->owner_type,
            'owner_id' => $account->owner_id,
            'name' => $account->name,
            'purpose' => AccountPurpose::Hold,
            'currency' => $account->currency,
        ];

        $model = config('balance.models.account');
        $query = $model::query()->where($attributes);

        if ($existing = $query->first()) {
            return $existing;
        }

        // Same reasoning as HasBalance::resolveBalanceAccount(): the unique
        // index on (owner_type, owner_id, name, purpose, currency) is what
        // makes this correct, not the lookup above.
        try {
            return $model::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return $query->firstOrFail();
        }
    }
}
