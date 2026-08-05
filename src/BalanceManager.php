<?php

namespace Bityukov\BalanceEngine;

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\Deposited;
use Bityukov\BalanceEngine\Events\Withdrawn;
use Bityukov\BalanceEngine\Exceptions\InvalidAmount;
use Bityukov\BalanceEngine\Ledger\AccountLocker;
use Bityukov\BalanceEngine\Ledger\Line;
use Bityukov\BalanceEngine\Ledger\TransactionWriter;
use Bityukov\BalanceEngine\Models\Transaction;
use Bityukov\BalanceEngine\Support\AccountResolver;
use Bityukov\BalanceEngine\Support\Fingerprint;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BalanceManager
{
    public function __construct(
        protected AccountResolver $resolver,
        protected AccountLocker $locker,
        protected TransactionWriter $writer,
        protected SystemAccounts $system,
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
        $external = $this->system->external($account->currency);

        return $this->perform(function () use ($account, $external, $amount, $reference, $meta, $idempotencyKey) {
            $locked = $this->locker->lock([$external->getKey(), $account->getKey()]);

            $transaction = $this->writer->write(
                type: TransactionType::Deposit,
                lines: [
                    new Line($locked[$external->getKey()], -$amount),
                    new Line($locked[$account->getKey()], $amount),
                ],
                reference: $reference,
                meta: $meta,
                idempotencyKey: $idempotencyKey,
                idempotencyFingerprint: Fingerprint::make(
                    TransactionType::Deposit,
                    [$external->getKey(), $account->getKey()],
                    $amount,
                    $account->currency,
                ),
            );

            event(new Deposited($transaction, $locked[$account->getKey()], $amount));

            return $transaction;
        });
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
        $external = $this->system->external($account->currency);

        return $this->perform(function () use ($account, $external, $amount, $reference, $meta, $idempotencyKey) {
            $locked = $this->locker->lock([$external->getKey(), $account->getKey()]);

            $transaction = $this->writer->write(
                type: TransactionType::Withdraw,
                lines: [
                    new Line($locked[$account->getKey()], -$amount),
                    new Line($locked[$external->getKey()], $amount),
                ],
                reference: $reference,
                meta: $meta,
                idempotencyKey: $idempotencyKey,
                idempotencyFingerprint: Fingerprint::make(
                    TransactionType::Withdraw,
                    [$external->getKey(), $account->getKey()],
                    $amount,
                    $account->currency,
                ),
            );

            event(new Withdrawn($transaction, $locked[$account->getKey()], $amount));

            return $transaction;
        });
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
     */
    protected function perform(Closure $callback): Transaction
    {
        return DB::transaction($callback, config('balance.transaction_attempts'));
    }
}
