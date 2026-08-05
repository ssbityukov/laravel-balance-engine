<?php

namespace Bityukov\BalanceEngine\Ledger;

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;
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
        $entries = (new Entry)->getTable();
        $transactions = (new Transaction)->getTable();
        $accounts = (new Account)->getTable();

        $remaining = DB::table($entries.' as e')
            ->join($transactions.' as c', 'c.id', '=', 'e.transaction_id')
            ->join($accounts.' as a', 'a.id', '=', 'e.account_id')
            ->where('a.purpose', AccountPurpose::Hold->value)
            ->where(fn ($query) => $query
                ->whereColumn('c.id', 't.id')
                ->orWhereColumn('c.parent_id', 't.id'))
            ->selectRaw('coalesce(sum(e.amount), 0)');

        return Transaction::query()
            ->from($transactions.' as t')
            ->where('t.type', TransactionType::Reserve->value)
            ->whereNotNull('t.expires_at')
            ->where('t.expires_at', '<', now())
            ->whereRaw("({$remaining->toSql()}) > 0", $remaining->getBindings())
            ->orderBy('t.id')
            ->get()
            ->map(fn (Transaction $transaction) => new Reservation($transaction));
    }
}
