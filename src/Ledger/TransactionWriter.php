<?php

namespace Bityukov\BalanceEngine\Ledger;

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Exceptions\AccountFrozen;
use Bityukov\BalanceEngine\Exceptions\CurrencyMismatch;
use Bityukov\BalanceEngine\Exceptions\InsufficientFunds;
use Bityukov\BalanceEngine\Exceptions\InvalidAmount;
use Bityukov\BalanceEngine\Exceptions\UnbalancedTransaction;
use Bityukov\BalanceEngine\Exceptions\WriterOutsideTransaction;
use Bityukov\BalanceEngine\Models\Transaction;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only place in the package that writes ledger entries.
 *
 * Callers pass a balanced set of lines built from *already locked* accounts.
 * Every invariant that protects the ledger lives here, which is why there is
 * no interface for this class: a swappable writer is an invitation to break
 * double-entry.
 */
class TransactionWriter
{
    /**
     * @param  array<int, Line>  $lines
     * @param  array<string, mixed>|null  $meta
     */
    public function write(
        TransactionType $type,
        array $lines,
        ?Model $reference = null,
        ?array $meta = null,
        ?string $idempotencyKey = null,
        ?string $idempotencyFingerprint = null,
        ?Transaction $reverses = null,
        ?DateTimeInterface $expiresAt = null,
        ?Transaction $parent = null,
    ): Transaction {
        $this->assertInsideTransaction();
        $this->assertBalanced($lines);
        $this->assertSingleCurrency($lines);

        $transaction = $this->newTransaction()->create([
            'type' => $type,
            'idempotency_key' => $idempotencyKey,
            'idempotency_fingerprint' => $idempotencyFingerprint,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'reverses_id' => $reverses?->getKey(),
            'parent_id' => $parent?->getKey(),
            'expires_at' => $expiresAt,
            'meta' => $meta,
        ]);

        foreach ($lines as $line) {
            $this->writeLine($transaction, $line);
        }

        return $transaction;
    }

    protected function writeLine(Transaction $transaction, Line $line): void
    {
        $account = $line->account;
        $balanceAfter = $account->balance + $line->amount;

        if ($line->amount < 0 && $account->isFrozen()) {
            throw AccountFrozen::for($account);
        }

        if ($balanceAfter < 0 && ! $account->allows_negative) {
            throw InsufficientFunds::for($account, abs($line->amount), $account->balance);
        }

        $this->newEntry()->create([
            'transaction_id' => $transaction->getKey(),
            'account_id' => $account->getKey(),
            'amount' => $line->amount,
            'currency' => $account->currency,
            'balance_after' => $balanceAfter,
        ]);

        $account->forceFill(['balance' => $balanceAfter])->save();
    }

    protected function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw WriterOutsideTransaction::make();
        }
    }

    /**
     * @param  array<int, Line>  $lines
     */
    protected function assertBalanced(array $lines): void
    {
        if ($lines === []) {
            throw UnbalancedTransaction::empty();
        }

        $sum = 0;

        foreach ($lines as $line) {
            if ($line->amount === 0) {
                throw InvalidAmount::zero();
            }

            $sum += $line->amount;
        }

        if ($sum !== 0) {
            throw UnbalancedTransaction::for($sum);
        }
    }

    /**
     * @param  array<int, Line>  $lines
     */
    protected function assertSingleCurrency(array $lines): void
    {
        $expected = $lines[array_key_first($lines)]->account->currency;

        foreach ($lines as $line) {
            if ($line->account->currency !== $expected) {
                throw CurrencyMismatch::between($expected, $line->account->currency);
            }
        }
    }

    protected function newTransaction(): Transaction
    {
        $class = config('balance.models.transaction');

        return new $class;
    }

    protected function newEntry(): Model
    {
        $class = config('balance.models.entry');

        return new $class;
    }
}
