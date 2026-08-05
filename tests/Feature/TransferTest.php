<?php

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\Transferred;
use Bityukov\BalanceEngine\Exceptions\CannotTransferToSelf;
use Bityukov\BalanceEngine\Exceptions\CurrencyMismatch;
use Bityukov\BalanceEngine\Exceptions\InsufficientFunds;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->alice = User::create(['name' => 'alice']);
    $this->bob = User::create(['name' => 'bob']);

    Balance::deposit(to: $this->alice, amount: 10_000);
});

it('moves money between two owners', function () {
    Balance::transfer(from: $this->alice, to: $this->bob, amount: 4_000);

    expect($this->alice->balanceAmount())->toBe(6_000)
        ->and($this->bob->balanceAmount())->toBe(4_000);
});

it('writes exactly two entries', function () {
    $transaction = Balance::transfer(from: $this->alice, to: $this->bob, amount: 4_000);

    expect($transaction->entries)->toHaveCount(2)
        ->and($transaction->type)->toBe(TransactionType::Transfer);
});

it('leaves the system total untouched', function () {
    $before = (int) Entry::sum('amount');

    Balance::transfer(from: $this->alice, to: $this->bob, amount: 4_000);

    expect((int) Entry::sum('amount'))->toBe($before)->toBe(0);
});

it('does not touch the external system account', function () {
    $external = app(SystemAccounts::class)->external('USD');
    $before = $external->fresh()->balance;

    Balance::transfer(from: $this->alice, to: $this->bob, amount: 4_000);

    expect($external->fresh()->balance)->toBe($before);
});

it('records balance_after for both sides', function () {
    $transaction = Balance::transfer(from: $this->alice, to: $this->bob, amount: 4_000);

    $entries = $transaction->entries->keyBy('account_id');

    expect($entries[$this->alice->balanceAccount()->id]->balance_after)->toBe(6_000)
        ->and($entries[$this->bob->balanceAccount()->id]->balance_after)->toBe(4_000);
});

it('refuses to overdraw the sender', function () {
    expect(fn () => Balance::transfer(from: $this->alice, to: $this->bob, amount: 10_001))
        ->toThrow(InsufficientFunds::class);

    expect($this->alice->balanceAmount())->toBe(10_000)
        ->and($this->bob->balanceAmount())->toBe(0);
});

it('refuses to transfer between different currencies', function () {
    expect(fn () => Balance::transfer(
        from: $this->alice->balanceAccount(),
        to: $this->bob->balanceAccount('main', 'EUR'),
        amount: 100,
    ))->toThrow(CurrencyMismatch::class);
});

it('refuses to transfer an account to itself', function () {
    expect(fn () => Balance::transfer(from: $this->alice, to: $this->alice, amount: 100))
        ->toThrow(CannotTransferToSelf::class);
});

it('transfers between two named accounts of the same owner', function () {
    Balance::transfer(
        from: $this->alice->balanceAccount(),
        to: $this->alice->balanceAccount('bonus'),
        amount: 2_500,
    );

    expect($this->alice->balanceAmount())->toBe(7_500)
        ->and($this->alice->balanceAmount('bonus'))->toBe(2_500);
});

it('dispatches Transferred with both accounts', function () {
    Event::fake([Transferred::class]);

    Balance::transfer(from: $this->alice, to: $this->bob, amount: 100);

    Event::assertDispatched(Transferred::class, function (Transferred $event) {
        return $event->amount === 100
            && $event->from->owner_id === $this->alice->getKey()
            && $event->to->owner_id === $this->bob->getKey();
    });
});
