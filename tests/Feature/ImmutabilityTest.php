<?php

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Exceptions\ImmutableRecord;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;

beforeEach(function () {
    $this->account = Account::create(['currency' => 'USD']);

    $this->transaction = Transaction::create(['type' => TransactionType::Deposit]);

    $this->entry = Entry::create([
        'transaction_id' => $this->transaction->id,
        'account_id' => $this->account->id,
        'amount' => 100,
        'currency' => 'USD',
        'balance_after' => 100,
    ]);
});

it('refuses to update an entry', function () {
    expect(fn () => $this->entry->update(['amount' => 200]))
        ->toThrow(ImmutableRecord::class);
});

it('refuses to delete an entry', function () {
    expect(fn () => $this->entry->delete())
        ->toThrow(ImmutableRecord::class);
});

it('refuses to update a transaction', function () {
    expect(fn () => $this->transaction->update(['type' => TransactionType::Withdraw]))
        ->toThrow(ImmutableRecord::class);
});

it('refuses to delete a transaction', function () {
    expect(fn () => $this->transaction->delete())
        ->toThrow(ImmutableRecord::class);
});

it('leaves the stored row untouched after a refused update', function () {
    try {
        $this->entry->update(['amount' => 200]);
    } catch (ImmutableRecord) {
        // expected
    }

    expect(Entry::find($this->entry->id)->amount)->toBe(100);
});

it('still allows accounts to be updated', function () {
    $this->account->update(['balance' => 500]);

    expect($this->account->fresh()->balance)->toBe(500);
});
