<?php

namespace Bityukov\BalanceEngine\Support;

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Checks the invariants that make the cached balance columns trustworthy.
 * Read-only by design: repairs live in balance:rebuild, because an automatic
 * fix would mask a bug in the ledger itself.
 */
class LedgerVerifier
{
    /**
     * @return array<int, VerificationProblem>
     */
    public function verify(?int $accountId = null): array
    {
        return array_merge(
            $accountId === null ? $this->checkGlobalSum() : [],
            $this->checkAccountBalances($accountId),
            $accountId === null ? $this->checkReservationSettlement() : [],
            $accountId === null ? $this->checkReservationParents() : [],
            $this->checkMorphMap($accountId),
            $this->checkHoldAccounts($accountId),
        );
    }

    /**
     * Invariant 2: the whole book sums to zero.
     *
     * @return array<int, VerificationProblem>
     */
    protected function checkGlobalSum(): array
    {
        $sum = (int) $this->entries()->sum('amount');

        if ($sum === 0) {
            return [];
        }

        return [new VerificationProblem(
            'global_sum',
            "Sum of all ledger entries is {$sum}, expected 0.",
        )];
    }

    /**
     * Invariant 3: the cached balance column matches the entries behind it.
     *
     * @return array<int, VerificationProblem>
     */
    protected function checkAccountBalances(?int $accountId): array
    {
        $entries = $this->table('entries');
        $accounts = $this->table('accounts');

        $drifted = $this->accounts()
            ->when($accountId !== null, fn ($query) => $query->whereKey($accountId))
            ->whereRaw(
                "{$accounts}.balance <> (select coalesce(sum(amount), 0) from {$entries} where {$entries}.account_id = {$accounts}.id)"
            )
            ->get();

        return $drifted->map(function ($account) {
            $ledger = (int) $this->entries()->where('account_id', $account->getKey())->sum('amount');

            return new VerificationProblem(
                'account_balance',
                sprintf(
                    'Account [%d] has cached balance %d but its entries sum to %d.',
                    $account->getKey(),
                    $account->balance,
                    $ledger,
                ),
            );
        })->all();
    }

    /**
     * Invariant 7: no reservation's children draw more than the reserve put on
     * the hold account.
     *
     * Grouping by COALESCE(parent_id, id) collapses a reserve and all of its
     * captures and releases onto one row, so there is no need to know which
     * hold account the chain uses. Restricting to hold accounts drops the other
     * side of each entry pair — the available account on a reserve, the
     * recipient on a capture.
     *
     * @return array<int, VerificationProblem>
     */
    protected function checkReservationSettlement(): array
    {
        $entries = $this->table('entries');
        $transactions = $this->table('transactions');
        $accounts = $this->table('accounts');

        $overdrawn = DB::table($entries.' as e')
            ->join($transactions.' as t', 't.id', '=', 'e.transaction_id')
            ->join($accounts.' as a', 'a.id', '=', 'e.account_id')
            ->where('a.purpose', AccountPurpose::Hold->value)
            ->where(fn ($query) => $query
                ->where('t.type', TransactionType::Reserve->value)
                ->orWhereNotNull('t.parent_id'))
            ->groupBy(DB::raw('coalesce(t.parent_id, t.id)'))
            ->havingRaw('sum(e.amount) < 0')
            ->selectRaw('coalesce(t.parent_id, t.id) as reserve_id, sum(e.amount) as remaining')
            ->get();

        return $overdrawn->map(fn ($row) => new VerificationProblem(
            'reservation_settlement',
            sprintf(
                'Reservation [%d] has been drawn down past zero: remaining is %d.',
                $row->reserve_id,
                $row->remaining,
            ),
        ))->all();
    }

    /**
     * Invariant 9: every capture and release points at a reserve.
     *
     * @return array<int, VerificationProblem>
     */
    protected function checkReservationParents(): array
    {
        $transactions = $this->table('transactions');

        $orphans = DB::table($transactions.' as c')
            ->leftJoin($transactions.' as p', 'p.id', '=', 'c.parent_id')
            ->whereIn('c.type', [TransactionType::Capture->value, TransactionType::Release->value])
            ->where(fn ($query) => $query
                ->whereNull('c.parent_id')
                ->orWhere('p.type', '<>', TransactionType::Reserve->value))
            ->select('c.id', 'c.type', 'c.parent_id')
            ->get();

        return $orphans->map(fn ($row) => new VerificationProblem(
            'reservation_parent',
            $row->parent_id === null
                ? sprintf('Transaction [%d] of type [%s] has no parent reservation.', $row->id, $row->type)
                : sprintf(
                    'Transaction [%d] of type [%s] is parented to [%d], which is not a reserve.',
                    $row->id,
                    $row->type,
                    $row->parent_id,
                ),
        ))->all();
    }

    /**
     * @return array<int, VerificationProblem>
     */
    protected function checkMorphMap(?int $accountId): array
    {
        $types = $this->accounts()
            ->when($accountId !== null, fn ($query) => $query->whereKey($accountId))
            ->whereNotNull('owner_type')
            ->distinct()
            ->pluck('owner_type');

        return $types
            ->reject(fn (string $type) => Relation::getMorphedModel($type) !== null || class_exists($type))
            ->map(fn (string $type) => new VerificationProblem(
                'morph_map',
                "Owner type [{$type}] resolves to nothing. Register it with Relation::enforceMorphMap().",
            ))
            ->values()
            ->all();
    }

    /**
     * Invariant 8: a hold account never goes negative.
     *
     * @return array<int, VerificationProblem>
     */
    protected function checkHoldAccounts(?int $accountId): array
    {
        return $this->accounts()
            ->when($accountId !== null, fn ($query) => $query->whereKey($accountId))
            ->where('purpose', AccountPurpose::Hold)
            ->where('balance', '<', 0)
            ->get()
            ->map(fn ($account) => new VerificationProblem(
                'negative_hold',
                sprintf('Hold account [%d] has a negative balance of %d.', $account->getKey(), $account->balance),
            ))
            ->all();
    }

    protected function accounts()
    {
        return config('balance.models.account')::query();
    }

    protected function entries()
    {
        return config('balance.models.entry')::query();
    }

    protected function table(string $name): string
    {
        return DB::getTablePrefix().config('balance.table_prefix').$name;
    }
}
