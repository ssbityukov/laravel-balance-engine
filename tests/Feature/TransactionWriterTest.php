<?php

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Exceptions\AccountFrozen;
use Bityukov\BalanceEngine\Exceptions\CurrencyMismatch;
use Bityukov\BalanceEngine\Exceptions\InsufficientFunds;
use Bityukov\BalanceEngine\Exceptions\InvalidAmount;
use Bityukov\BalanceEngine\Exceptions\UnbalancedTransaction;
use Bityukov\BalanceEngine\Exceptions\WriterOutsideTransaction;
use Bityukov\BalanceEngine\Ledger\Line;
use Bityukov\BalanceEngine\Ledger\TransactionWriter;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Tests\Fixtures\Order;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->writer = app(TransactionWriter::class);

    $this->source = Account::create([
        'name' => 'source',
        'currency' => 'USD',
        'balance' => 1_000,
    ]);

    $this->target = Account::create([
        'name' => 'target',
        'currency' => 'USD',
        'balance' => 0,
    ]);
});

/**
 * Every test that expects a successful write must open a transaction itself:
 * the writer refuses to run outside one.
 */
function writeInTransaction(Closure $callback): mixed
{
    return DB::transaction($callback);
}

it('refuses to write outside a database transaction', function () {
    expect(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -100), new Line($this->target, 100)],
    ))->toThrow(WriterOutsideTransaction::class);
});

it('rejects an empty set of lines', function () {
    expect(fn () => writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Deposit,
        lines: [],
    )))->toThrow(UnbalancedTransaction::class);
});

it('rejects a zero amount line', function () {
    expect(fn () => writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, 0), new Line($this->target, 0)],
    )))->toThrow(InvalidAmount::class);
});

it('rejects lines that do not sum to zero', function () {
    expect(fn () => writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -100), new Line($this->target, 99)],
    )))->toThrow(UnbalancedTransaction::class);
});

it('rejects lines spanning two currencies', function () {
    $euro = Account::create(['name' => 'euro', 'currency' => 'EUR', 'balance' => 1_000]);

    expect(fn () => writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($euro, -100), new Line($this->target, 100)],
    )))->toThrow(CurrencyMismatch::class);
});

it('rejects a debit that would overdraw the account', function () {
    expect(fn () => writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -1_001), new Line($this->target, 1_001)],
    )))->toThrow(InsufficientFunds::class);
});

it('allows an overdraft on accounts flagged allows_negative', function () {
    $system = Account::create([
        'code' => 'system:external',
        'name' => 'external',
        'currency' => 'USD',
        'allows_negative' => true,
    ]);

    writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Deposit,
        lines: [new Line($system, -5_000), new Line($this->target, 5_000)],
    ));

    expect($system->fresh()->balance)->toBe(-5_000)
        ->and($this->target->fresh()->balance)->toBe(5_000);
});

it('rejects a debit from a frozen account', function () {
    $this->source->update(['frozen_at' => now()]);

    expect(fn () => writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source->fresh(), -100), new Line($this->target, 100)],
    )))->toThrow(AccountFrozen::class);
});

it('allows a credit to a frozen account', function () {
    $this->target->update(['frozen_at' => now()]);

    writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -100), new Line($this->target->fresh(), 100)],
    ));

    expect($this->target->fresh()->balance)->toBe(100);
});

it('writes one entry per line and updates cached balances', function () {
    $transaction = writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -250), new Line($this->target, 250)],
    ));

    expect($transaction->entries)->toHaveCount(2)
        ->and($this->source->fresh()->balance)->toBe(750)
        ->and($this->target->fresh()->balance)->toBe(250);
});

it('records balance_after on each entry', function () {
    writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -250), new Line($this->target, 250)],
    ));

    expect(Entry::where('account_id', $this->source->id)->value('balance_after'))->toBe(750)
        ->and(Entry::where('account_id', $this->target->id)->value('balance_after'))->toBe(250);
});

it('keeps the global sum of entries at zero', function () {
    writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -250), new Line($this->target, 250)],
    ));

    expect((int) Entry::sum('amount'))->toBe(0);
});

it('stores reference, meta and idempotency data', function () {
    $user = Order::create();

    $transaction = writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -100), new Line($this->target, 100)],
        reference: $user,
        meta: ['note' => 'test'],
        idempotencyKey: 'key-1',
        idempotencyFingerprint: 'fp-1',
    ))->fresh();

    expect($transaction->reference->is($user))->toBeTrue()
        ->and($transaction->meta)->toBe(['note' => 'test'])
        ->and($transaction->idempotency_key)->toBe('key-1')
        ->and($transaction->idempotency_fingerprint)->toBe('fp-1');
});

it('rolls back everything when a later line fails validation', function () {
    $frozen = Account::create(['name' => 'frozen', 'currency' => 'USD', 'frozen_at' => now()]);

    try {
        writeInTransaction(fn () => $this->writer->write(
            type: TransactionType::Transfer,
            lines: [new Line($this->target, 100), new Line($frozen, -100)],
        ));
    } catch (AccountFrozen) {
        // expected
    }

    expect(Entry::count())->toBe(0)
        ->and($this->target->fresh()->balance)->toBe(0);
});

it('links a reversal to the original transaction', function () {
    $original = writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Transfer,
        lines: [new Line($this->source, -100), new Line($this->target, 100)],
    ));

    $reversal = writeInTransaction(fn () => $this->writer->write(
        type: TransactionType::Reversal,
        lines: [new Line($this->source->fresh(), 100), new Line($this->target->fresh(), -100)],
        reverses: $original,
    ));

    expect($reversal->reverses_id)->toBe($original->id);
});
