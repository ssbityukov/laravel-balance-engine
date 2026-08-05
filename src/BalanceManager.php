<?php

namespace Bityukov\BalanceEngine;

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\Deposited;
use Bityukov\BalanceEngine\Events\ReservationCaptured;
use Bityukov\BalanceEngine\Events\ReservationReleased;
use Bityukov\BalanceEngine\Events\Reserved;
use Bityukov\BalanceEngine\Events\Transferred;
use Bityukov\BalanceEngine\Events\Withdrawn;
use Bityukov\BalanceEngine\Exceptions\CannotTransferToSelf;
use Bityukov\BalanceEngine\Exceptions\CurrencyMismatch;
use Bityukov\BalanceEngine\Exceptions\InvalidAmount;
use Bityukov\BalanceEngine\Exceptions\ReservationAmountExceeded;
use Bityukov\BalanceEngine\Exceptions\ReservationClosed;
use Bityukov\BalanceEngine\Exceptions\ReservationExpired;
use Bityukov\BalanceEngine\Ledger\AccountLocker;
use Bityukov\BalanceEngine\Ledger\IdempotencyGuard;
use Bityukov\BalanceEngine\Ledger\Line;
use Bityukov\BalanceEngine\Ledger\Reservation;
use Bityukov\BalanceEngine\Ledger\TransactionWriter;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Transaction;
use Bityukov\BalanceEngine\Support\AccountResolver;
use Bityukov\BalanceEngine\Support\Fingerprint;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BalanceManager
{
    public function __construct(
        protected AccountResolver $resolver,
        protected AccountLocker $locker,
        protected TransactionWriter $writer,
        protected SystemAccounts $system,
        protected IdempotencyGuard $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function deposit(
        Model $to,
        int $amount,
        ?string $currency = null,
        ?Model $reference = null,
        ?array $meta = null,
        ?string $idempotencyKey = null,
    ): Transaction {
        $this->assertPositive($amount);

        $account = $this->resolver->resolve($to, $currency);

        return $this->post(
            type: TransactionType::Deposit,
            debit: $this->system->external($account->currency),
            credit: $account,
            amount: $amount,
            reference: $reference,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            event: fn (Transaction $transaction, Account $debit, Account $credit) => new Deposited($transaction, $credit, $amount),
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function withdraw(
        Model $from,
        int $amount,
        ?string $currency = null,
        ?Model $reference = null,
        ?array $meta = null,
        ?string $idempotencyKey = null,
    ): Transaction {
        $this->assertPositive($amount);

        $account = $this->resolver->resolve($from, $currency);

        return $this->post(
            type: TransactionType::Withdraw,
            debit: $account,
            credit: $this->system->external($account->currency),
            amount: $amount,
            reference: $reference,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            event: fn (Transaction $transaction, Account $debit, Account $credit) => new Withdrawn($transaction, $debit, $amount),
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function transfer(
        Model $from,
        Model $to,
        int $amount,
        ?string $currency = null,
        ?Model $reference = null,
        ?array $meta = null,
        ?string $idempotencyKey = null,
    ): Transaction {
        $this->assertPositive($amount);

        $source = $this->resolver->resolve($from, $currency);
        $destination = $this->resolver->resolve($to, $currency);

        // A self transfer nets to nothing, but it would still write a
        // transaction and two entries describing a movement that never
        // happened, and it would fail with InsufficientFunds whenever the
        // amount exceeds the balance despite that zero net effect. Neither is
        // worth supporting, so it is refused outright.
        if ($source->is($destination)) {
            throw CannotTransferToSelf::for($source);
        }

        // The writer checks this again. Failing here avoids opening a
        // transaction for nothing and gives a stack trace that points at the
        // caller rather than at the ledger.
        if ($source->currency !== $destination->currency) {
            throw CurrencyMismatch::between($source->currency, $destination->currency);
        }

        return $this->post(
            type: TransactionType::Transfer,
            debit: $source,
            credit: $destination,
            amount: $amount,
            reference: $reference,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            event: fn (Transaction $transaction, Account $debit, Account $credit) => new Transferred($transaction, $debit, $credit, $amount),
        );
    }

    /**
     * Put money aside on the owner's hold account. The reservation that comes
     * back is a read-model over the transaction, not a stored row.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function reserve(
        Model $from,
        int $amount,
        ?string $currency = null,
        ?DateTimeInterface $expiresAt = null,
        ?Model $reference = null,
        ?array $meta = null,
        ?string $idempotencyKey = null,
    ): Reservation {
        $this->assertPositive($amount);

        $account = $this->resolver->resolve($from, $currency);

        $transaction = $this->post(
            type: TransactionType::Reserve,
            debit: $account,
            credit: $this->resolver->hold($account),
            amount: $amount,
            reference: $reference,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            expiresAt: $expiresAt,
            // The event needs the reservation, which needs the transaction that
            // does not exist yet, so it is built here rather than passed in.
            event: fn (Transaction $transaction, Account $debit, Account $credit) => new Reserved(
                new Reservation($transaction),
                $transaction,
            ),
        );

        return new Reservation($transaction);
    }

    /**
     * Take some or all of a reservation and hand it to someone. Defaults to
     * whatever is still held.
     *
     * The destination is mandatory: a default system recipient would let money
     * quietly drift onto a system account and go unnoticed for years.
     */
    public function capture(Reservation $reservation, Model $to, ?int $amount = null): Transaction
    {
        $hold = $reservation->holdAccount();
        $destination = $this->resolver->resolve($to, $hold->currency);

        return $this->drawDown(
            reservation: $reservation,
            type: TransactionType::Capture,
            destination: $destination,
            amount: $amount,
            expired: fn () => throw ReservationExpired::for($reservation),
            event: fn (Transaction $transaction, int $captured) => new ReservationCaptured(
                $reservation,
                $transaction,
                $captured,
            ),
        );
    }

    /**
     * Give some or all of a reservation back to the account it came from.
     *
     * Unlike capture, this is allowed on an expired reservation. Refusing it
     * would strand the money on the hold account forever and leave
     * balance:expire-reservations with nothing it could do.
     */
    public function release(Reservation $reservation, ?int $amount = null): Transaction
    {
        return $this->drawDown(
            reservation: $reservation,
            type: TransactionType::Release,
            destination: $reservation->account(),
            amount: $amount,
            expired: null,
            event: fn (Transaction $transaction, int $released) => new ReservationReleased(
                $reservation,
                $transaction,
                $released,
            ),
        );
    }

    /**
     * The shared body of capture and release: move money off the hold account
     * as a child of the reserve transaction.
     *
     * remaining() is read twice on purpose. Once here, unlocked, only to size a
     * null $amount, and once inside the guard with the hold account locked to
     * decide whether the operation may proceed at all. If a concurrent
     * draw-down shrank the reservation in between, the guard rejects it.
     *
     * @param  Closure():never|null  $expired  what to do when the reservation has elapsed; null allows it
     * @param  Closure(Transaction, int): object  $event
     */
    protected function drawDown(
        Reservation $reservation,
        TransactionType $type,
        Account $destination,
        ?int $amount,
        ?Closure $expired,
        Closure $event,
    ): Transaction {
        $amount ??= $reservation->remaining();

        $this->assertPositive($amount);

        return $this->post(
            type: $type,
            debit: $reservation->holdAccount(),
            credit: $destination,
            amount: $amount,
            reference: null,
            meta: null,
            idempotencyKey: null,
            parent: $reservation->transaction,
            guard: function () use ($reservation, $amount, $expired) {
                if ($reservation->remaining() <= 0) {
                    throw ReservationClosed::for($reservation);
                }

                if ($expired !== null && $reservation->isExpired()) {
                    $expired();
                }

                if ($amount > $reservation->remaining()) {
                    throw ReservationAmountExceeded::for($reservation, $amount);
                }
            },
            event: fn (Transaction $transaction) => $event($transaction, $amount),
        );
    }

    /**
     * The shared body of every two-sided money operation: lock both accounts,
     * write one debit and one credit, announce it.
     *
     * The accounts handed to $event are the locked instances the writer
     * actually posted against, not the ones the caller resolved, so a listener
     * reading ->balance sees the balance after this operation.
     *
     * @param  array<string, mixed>|null  $meta
     * @param  Closure(Transaction, Account, Account): object  $event
     */
    protected function post(
        TransactionType $type,
        Account $debit,
        Account $credit,
        int $amount,
        ?Model $reference,
        ?array $meta,
        ?string $idempotencyKey,
        Closure $event,
        ?DateTimeInterface $expiresAt = null,
        ?Transaction $parent = null,
        ?Closure $guard = null,
    ): Transaction {
        // Computed before the transaction opens so perform() can compare it
        // against a stored one without doing any of the work first.
        $fingerprint = Fingerprint::make(
            $type,
            [$debit->getKey(), $credit->getKey()],
            $amount,
            $debit->currency,
        );

        return $this->perform(
            callback: function () use ($type, $debit, $credit, $amount, $reference, $meta, $idempotencyKey, $fingerprint, $expiresAt, $parent, $guard, $event) {
                $locked = $this->locker->lock([$debit->getKey(), $credit->getKey()]);

                $from = $locked[$debit->getKey()];
                $to = $locked[$credit->getKey()];

                // Anything derived from the ledger has to be checked here, with
                // the rows already locked. Checking before perform() would let
                // two concurrent callers read the same state and both proceed.
                if ($guard !== null) {
                    $guard($from, $to);
                }

                $transaction = $this->writer->write(
                    type: $type,
                    lines: [
                        new Line($from, -$amount),
                        new Line($to, $amount),
                    ],
                    reference: $reference,
                    meta: $meta,
                    idempotencyKey: $idempotencyKey,
                    idempotencyFingerprint: $fingerprint,
                    expiresAt: $expiresAt,
                    parent: $parent,
                );

                event($event($transaction, $from, $to));

                return $transaction;
            },
            idempotencyKey: $idempotencyKey,
            fingerprint: $fingerprint,
        );
    }

    protected function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw InvalidAmount::notPositive($amount);
        }
    }

    /**
     * Run one money operation. Deadlock retries only take effect when the caller
     * has not wrapped this in an outer transaction of its own.
     *
     * The replay check happens twice on purpose: once before opening the
     * transaction, which covers the ordinary repeated call, and once after a
     * unique-index violation, which covers two concurrent workers racing on the
     * same key. Only the index can settle that race, so the second check is not
     * redundant.
     */
    protected function perform(Closure $callback, ?string $idempotencyKey = null, ?string $fingerprint = null): Transaction
    {
        if ($idempotencyKey !== null && $fingerprint !== null) {
            if ($replayed = $this->idempotency->replay($idempotencyKey, $fingerprint)) {
                return $replayed;
            }
        }

        try {
            return DB::transaction($callback, config('balance.transaction_attempts'));
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey === null || $fingerprint === null) {
                throw $e;
            }

            return $this->idempotency->replayOrFail($idempotencyKey, $fingerprint);
        }
    }
}
