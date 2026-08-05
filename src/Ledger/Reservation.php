<?php

namespace Bityukov\BalanceEngine\Ledger;

use Bityukov\BalanceEngine\BalanceManager;
use Bityukov\BalanceEngine\Enums\ReservationStatus;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A read-model over a reserve transaction, deliberately not an Eloquent model.
 *
 * There is no reservations table. A reservation *is* the reserve transaction;
 * captures and releases are its children through parent_id. Every number below
 * is derived from the ledger rather than stored, which is what keeps the
 * "records are immutable" invariant whole — a stored status column would be the
 * one mutable thing in the package.
 */
class Reservation
{
    /**
     * Both sides of the reserve transaction never change once written, so they
     * are read once and kept. Without this, status() alone would re-read the
     * hold account for each of the sums it combines.
     */
    private ?Account $account = null;

    private ?Account $holdAccount = null;

    public function __construct(
        public readonly Transaction $transaction,
    ) {}

    /**
     * Hand some or all of this reservation to someone. Defaults to the whole
     * remainder. The destination is mandatory on purpose.
     */
    public function capture(Model $to, ?int $amount = null): Transaction
    {
        return app(BalanceManager::class)->capture($this, $to, $amount);
    }

    /**
     * Give some or all of this reservation back. Defaults to the whole
     * remainder.
     */
    public function release(?int $amount = null): Transaction
    {
        return app(BalanceManager::class)->release($this, $amount);
    }

    /**
     * What was originally put on hold: the credit side of the reserve
     * transaction.
     */
    public function amount(): int
    {
        return (int) $this->entries()->where('amount', '>', 0)->value('amount');
    }

    /**
     * What is still held. The sum works out on its own: the reserve puts
     * +amount on the hold account and every capture or release takes some of it
     * back off, so whatever is left on the account for this chain is the
     * remainder.
     */
    public function remaining(): int
    {
        return $this->sumOnHold();
    }

    public function captured(): int
    {
        return -$this->sumOnHold(TransactionType::Capture);
    }

    public function released(): int
    {
        return -$this->sumOnHold(TransactionType::Release);
    }

    /**
     * Derived, never stored.
     */
    public function status(): ReservationStatus
    {
        if ($this->remaining() > 0) {
            return $this->isExpired()
                ? ReservationStatus::Expired
                : ReservationStatus::Open;
        }

        return $this->captured() > 0
            ? ReservationStatus::Captured
            : ReservationStatus::Released;
    }

    public function isOpen(): bool
    {
        return $this->status() === ReservationStatus::Open;
    }

    /**
     * A reservation created without an expiry never expires.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt()?->isPast() ?? false;
    }

    public function expiresAt(): ?Carbon
    {
        return $this->transaction->expires_at;
    }

    /**
     * The available account the money came from: the debit side.
     */
    public function account(): Account
    {
        return $this->account ??= $this->entries()->where('amount', '<', 0)->firstOrFail()->account;
    }

    /**
     * The hold account the money sits on: the credit side.
     */
    public function holdAccount(): Account
    {
        return $this->holdAccount ??= $this->entries()->where('amount', '>', 0)->firstOrFail()->account;
    }

    /**
     * Sum this reservation's entries on its hold account, optionally narrowed
     * to one kind of child transaction.
     *
     * Scoped to the reserve transaction and its children rather than to the
     * account, because one hold account carries every reservation an owner has.
     */
    protected function sumOnHold(?TransactionType $type = null): int
    {
        $entries = (new Entry)->getTable();
        $transactions = (new Transaction)->getTable();
        $id = $this->transaction->getKey();

        $query = DB::table($entries.' as e')
            ->join($transactions.' as t', 't.id', '=', 'e.transaction_id')
            ->where('e.account_id', $this->holdAccount()->getKey())
            ->where(fn ($q) => $q->where('t.id', $id)->orWhere('t.parent_id', $id));

        if ($type !== null) {
            $query->where('t.type', $type->value);
        }

        return (int) $query->sum('e.amount');
    }

    /**
     * @return Builder<Entry>
     */
    protected function entries()
    {
        return $this->transaction->entries()->getQuery();
    }
}
