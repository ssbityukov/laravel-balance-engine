<?php

namespace Bityukov\BalanceEngine\Ledger;

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finding reservations without a status column to filter on.
 *
 * This is the stated price of deriving reservation state instead of storing it:
 * what used to be where('status', 'open') is now an aggregate subquery over the
 * hold account. Worth it — a stored status is a second source of truth that can
 * disagree with the ledger, and this one cannot.
 */
class ReservationQuery
{
    /**
     * Reserves whose expiry has passed and that are still holding money.
     *
     * A reservation already settled by hand is skipped: its remainder is zero,
     * so there is nothing to release and it is not "expired" in any sense that
     * matters.
     *
     * @return Collection<int, Reservation>
     */
    public function expired(): Collection
    {
        return $this->reservations(fn ($query) => $query
            ->whereNotNull('t.expires_at')
            ->where('t.expires_at', '<', now()));
    }

    /**
     * Reserves still holding money whose expiry has not passed, or that have
     * none. These are the ones that can still be captured.
     *
     * @return Collection<int, Reservation>
     */
    public function open(): Collection
    {
        return $this->reservations(fn ($query) => $query
            ->where(fn ($inner) => $inner
                ->whereNull('t.expires_at')
                ->orWhere('t.expires_at', '>=', now())));
    }

    /**
     * Every reserve still holding money, elapsed or not. Anything here can be
     * released, since releasing an elapsed reservation is allowed.
     *
     * @return Collection<int, Reservation>
     */
    public function unsettled(): Collection
    {
        return $this->reservations(fn ($query) => $query);
    }

    /**
     * @param  \Closure(Builder<Transaction>): mixed  $filter
     * @return Collection<int, Reservation>
     */
    protected function reservations(callable $filter): Collection
    {
        $transactions = (new Transaction)->getTable();
        $remaining = $this->remainingSubquery();

        return Transaction::query()
            ->from($transactions.' as t')
            ->where('t.type', TransactionType::Reserve->value)
            ->whereRaw("({$remaining->toSql()}) > 0", $remaining->getBindings())
            ->tap($filter)
            ->orderBy('t.id')
            ->get()
            ->map(fn (Transaction $transaction) => new Reservation($transaction));
    }

    /**
     * Sum of a reserve's own entry and its children's entries on hold accounts,
     * correlated to the outer `t` row. Restricting to hold accounts drops the
     * far side of each entry pair.
     */
    protected function remainingSubquery(): \Illuminate\Database\Query\Builder
    {
        $entries = (new Entry)->getTable();
        $transactions = (new Transaction)->getTable();
        $accounts = (new Account)->getTable();

        return DB::table($entries.' as e')
            ->join($transactions.' as c', 'c.id', '=', 'e.transaction_id')
            ->join($accounts.' as a', 'a.id', '=', 'e.account_id')
            ->where('a.purpose', AccountPurpose::Hold->value)
            ->where(fn ($query) => $query
                ->whereColumn('c.id', 't.id')
                ->orWhereColumn('c.parent_id', 't.id'))
            ->selectRaw('coalesce(sum(e.amount), 0)');
    }
}
