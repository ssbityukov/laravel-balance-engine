<?php

namespace Bityukov\BalanceEngine\Ledger;

use Bityukov\BalanceEngine\Models\Account;

/**
 * Serialises concurrent money operations on the account row itself.
 *
 * Locks are always taken one query at a time in ascending id order. A single
 * whereIn(...)->lockForUpdate() would rely on the planner's scan order, which
 * is not a guarantee — and two transfers in opposite directions between the
 * same pair of accounts would deadlock.
 */
class AccountLocker
{
    /**
     * @param  array<int, int>  $accountIds
     * @return array<int, Account> keyed by account id
     */
    public function lock(array $accountIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $accountIds)));
        sort($ids);

        $model = config('balance.models.account');
        $locked = [];

        foreach ($ids as $id) {
            $locked[$id] = $model::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $locked;
    }
}
