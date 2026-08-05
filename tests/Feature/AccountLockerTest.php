<?php

use Bityukov\BalanceEngine\Ledger\AccountLocker;
use Bityukov\BalanceEngine\Models\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->locker = app(AccountLocker::class);
});

/**
 * SQLite has no row-level locking, so lockForUpdate() adds nothing to the SQL
 * and the lock queries cannot be spotted by a "for update" suffix. Match the
 * single-key account reads instead: on this driver the tests can only prove
 * that the locker issues one ordered query per account, which is precisely the
 * behaviour that keeps MySQL and Postgres from deadlocking. That SQLite takes
 * no actual lock is the reason SqliteWarningTest exists.
 */
function accountLockQueries(array &$sink): void
{
    DB::listen(function ($query) use (&$sink) {
        if (str_starts_with($query->sql, 'select * from "balance_accounts" where')
            && count($query->bindings) === 1) {
            $sink[] = (int) $query->bindings[0];
        }
    });
}

it('returns accounts keyed by id', function () {
    $first = Account::create(['currency' => 'USD', 'name' => 'a']);
    $second = Account::create(['currency' => 'USD', 'name' => 'b']);

    $locked = DB::transaction(fn () => $this->locker->lock([$second->id, $first->id]));

    expect(array_keys($locked))->toBe([$first->id, $second->id])
        ->and($locked[$first->id])->toBeInstanceOf(Account::class);
});

it('locks accounts in ascending id order regardless of input order', function () {
    $ids = collect(range(1, 3))
        ->map(fn (int $i) => Account::create(['currency' => 'USD', 'name' => "a{$i}"])->id)
        ->all();

    $bound = [];
    accountLockQueries($bound);

    DB::transaction(fn () => $this->locker->lock([$ids[2], $ids[0], $ids[1]]));

    $sorted = $ids;
    sort($sorted);

    expect($bound)->toBe($sorted);
});

it('deduplicates repeated ids', function () {
    $account = Account::create(['currency' => 'USD']);

    $bound = [];
    accountLockQueries($bound);

    DB::transaction(fn () => $this->locker->lock([$account->id, $account->id, $account->id]));

    expect($bound)->toHaveCount(1);
});

it('returns freshly read accounts, not stale instances', function () {
    $account = Account::create(['currency' => 'USD', 'balance' => 100]);

    Account::where('id', $account->id)->update(['balance' => 999]);

    $locked = DB::transaction(fn () => $this->locker->lock([$account->id]));

    expect($locked[$account->id]->balance)->toBe(999);
});

it('fails loudly on a missing account', function () {
    expect(fn () => DB::transaction(fn () => $this->locker->lock([9_999])))
        ->toThrow(ModelNotFoundException::class);
});
