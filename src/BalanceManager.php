<?php

namespace Bityukov\BalanceEngine;

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\Deposited;
use Bityukov\BalanceEngine\Events\Transferred;
use Bityukov\BalanceEngine\Events\Withdrawn;
use Bityukov\BalanceEngine\Exceptions\CannotTransferToSelf;
use Bityukov\BalanceEngine\Exceptions\CurrencyMismatch;
use Bityukov\BalanceEngine\Exceptions\InvalidAmount;
use Bityukov\BalanceEngine\Ledger\AccountLocker;
use Bityukov\BalanceEngine\Ledger\IdempotencyGuard;
use Bityukov\BalanceEngine\Ledger\Line;
use Bityukov\BalanceEngine\Ledger\TransactionWriter;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Transaction;
use Bityukov\BalanceEngine\Support\AccountResolver;
use Bityukov\BalanceEngine\Support\Fingerprint;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Closure;
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
            callback: function () use ($type, $debit, $credit, $amount, $reference, $meta, $idempotencyKey, $fingerprint, $event) {
                $locked = $this->locker->lock([$debit->getKey(), $credit->getKey()]);

                $from = $locked[$debit->getKey()];
                $to = $locked[$credit->getKey()];

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
