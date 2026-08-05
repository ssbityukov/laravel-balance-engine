<?php

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\Withdrawn;
use Bityukov\BalanceEngine\Exceptions\AccountFrozen;
use Bityukov\BalanceEngine\Exceptions\InsufficientFunds;
use Bityukov\BalanceEngine\Exceptions\InvalidAmount;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::create();

    Balance::deposit(to: $this->user, amount: 10_000);
});

it('debits the owner account', function () {
    Balance::withdraw(from: $this->user, amount: 4_000);

    expect($this->user->balanceAmount())->toBe(6_000);
});

it('credits the external system account back', function () {
    Balance::withdraw(from: $this->user, amount: 4_000);

    expect(app(SystemAccounts::class)->external('USD')->fresh()->balance)->toBe(-6_000);
});

it('keeps the global sum at zero', function () {
    Balance::withdraw(from: $this->user, amount: 4_000);

    expect((int) Entry::sum('amount'))->toBe(0);
});

it('records the transaction type', function () {
    expect(Balance::withdraw(from: $this->user, amount: 100)->type)
        ->toBe(TransactionType::Withdraw);
});

it('allows withdrawing the exact balance', function () {
    Balance::withdraw(from: $this->user, amount: 10_000);

    expect($this->user->balanceAmount())->toBe(0);
});

it('refuses to overdraw', function () {
    expect(fn () => Balance::withdraw(from: $this->user, amount: 10_001))
        ->toThrow(InsufficientFunds::class);

    expect($this->user->balanceAmount())->toBe(10_000);
});

it('carries the shortfall details on the exception', function () {
    try {
        Balance::withdraw(from: $this->user, amount: 15_000);
    } catch (InsufficientFunds $e) {
        expect($e->requested)->toBe(15_000)
            ->and($e->available)->toBe(10_000);

        return;
    }

    $this->fail('InsufficientFunds was not thrown.');
});

it('refuses to debit a frozen account', function () {
    $this->user->balanceAccount()->update(['frozen_at' => now()]);

    expect(fn () => Balance::withdraw(from: $this->user, amount: 100))
        ->toThrow(AccountFrozen::class);
});

it('rejects a zero or negative amount', function (int $amount) {
    expect(fn () => Balance::withdraw(from: $this->user, amount: $amount))
        ->toThrow(InvalidAmount::class);
})->with([0, -1]);

it('dispatches Withdrawn after commit', function () {
    Event::fake([Withdrawn::class]);

    Balance::withdraw(from: $this->user, amount: 100);

    Event::assertDispatched(Withdrawn::class, fn (Withdrawn $event) => $event->amount === 100);
});
