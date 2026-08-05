<?php

namespace Bityukov\BalanceEngine\Events;

use Bityukov\BalanceEngine\Models\Account;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Named AccountWasFrozen rather than AccountFrozen so it does not collide with
 * the exception of that name: any file using both would otherwise need an
 * import alias.
 */
class AccountWasFrozen implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Account $account,
        public readonly ?string $reason = null,
    ) {}
}
