<?php

namespace Bityukov\BalanceEngine\Concerns;

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Support\Currency;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Every member is prefixed with "balance" on purpose: most host models
 * already have a `balance` attribute or accessor of their own.
 */
trait HasBalance
{
    /**
     * @return MorphMany<Account, $this>
     */
    public function balanceAccounts(): MorphMany
    {
        /** @var class-string<Account> $model */
        $model = config('balance.models.account');

        return $this->morphMany($model, 'owner');
    }

    public function balanceAccount(?string $name = null, ?string $currency = null): Account
    {
        return $this->resolveBalanceAccount(
            $name ?? config('balance.default_account_name'),
            $currency ?? config('balance.default_currency'),
            AccountPurpose::Available,
        );
    }

    public function balanceAmount(?string $name = null, ?string $currency = null): int
    {
        return $this->balanceAccount($name, $currency)->balance;
    }

    public function balanceReserved(?string $name = null, ?string $currency = null): int
    {
        return $this->resolveBalanceAccount(
            $name ?? config('balance.default_account_name'),
            $currency ?? config('balance.default_currency'),
            AccountPurpose::Hold,
        )->balance;
    }

    /**
     * Correctness here comes from the unique index, not from a pre-flight
     * check: two concurrent requests can both pass a `where(...)->first()`.
     */
    protected function resolveBalanceAccount(string $name, string $currency, AccountPurpose $purpose): Account
    {
        Currency::assertSupported($currency);

        $query = $this->balanceAccounts()
            ->where('name', $name)
            ->where('purpose', $purpose)
            ->where('currency', $currency);

        if ($existing = $query->first()) {
            return $existing;
        }

        try {
            return $this->balanceAccounts()->create([
                'name' => $name,
                'purpose' => $purpose,
                'currency' => $currency,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $query->firstOrFail();
        }
    }
}
