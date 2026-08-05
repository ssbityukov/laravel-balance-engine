<?php

namespace Bityukov\BalanceEngine\Support;

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Models\Account;

class SystemAccounts
{
    public const External = 'system:external';

    /**
     * The counterparty for money entering or leaving the system. Its balance is
     * negative by design: it equals how much has been paid in from outside.
     */
    public function external(string $currency): Account
    {
        Currency::assertSupported($currency);

        $model = config('balance.models.account');

        return $model::firstOrCreate(
            ['code' => static::External, 'currency' => $currency],
            [
                'name' => 'external',
                'purpose' => AccountPurpose::Available,
                'allows_negative' => true,
            ],
        );
    }
}
