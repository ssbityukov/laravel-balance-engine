<?php

use Bityukov\BalanceEngine\Events\AccountWasFrozen;
use Bityukov\BalanceEngine\Events\AccountWasUnfrozen;
use Bityukov\BalanceEngine\Exceptions\AccountFrozen;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->alice = User::create(['name' => 'alice']);
    $this->bob = User::create(['name' => 'bob']);

    Balance::deposit(to: $this->alice, amount: 10_000);
});

it('marks the account frozen with a reason', function () {
    $account = Balance::freeze($this->alice, reason: 'aml-review');

    expect($account->isFrozen())->toBeTrue()
        ->and($account->frozen_reason)->toBe('aml-review')
        ->and($this->alice->balanceAccount()->fresh()->frozen_at)->not->toBeNull();
});

it('blocks withdrawals from a frozen account', function () {
    Balance::freeze($this->alice);

    expect(fn () => Balance::withdraw(from: $this->alice, amount: 100))
        ->toThrow(AccountFrozen::class);
});

it('blocks outgoing transfers from a frozen account', function () {
    Balance::freeze($this->alice);

    expect(fn () => Balance::transfer(from: $this->alice, to: $this->bob, amount: 100))
        ->toThrow(AccountFrozen::class);
});

it('blocks reservations from a frozen account', function () {
    Balance::freeze($this->alice);

    expect(fn () => Balance::reserve(from: $this->alice, amount: 100))
        ->toThrow(AccountFrozen::class);
});

it('still accepts deposits into a frozen account', function () {
    Balance::freeze($this->alice);

    Balance::deposit(to: $this->alice, amount: 5_000);

    expect($this->alice->balanceAmount())->toBe(15_000);
});

it('still accepts incoming transfers to a frozen account', function () {
    Balance::deposit(to: $this->bob, amount: 1_000);
    Balance::freeze($this->alice);

    Balance::transfer(from: $this->bob, to: $this->alice, amount: 1_000);

    expect($this->alice->balanceAmount())->toBe(11_000);
});

it('restores debits after unfreezing', function () {
    Balance::freeze($this->alice);
    Balance::unfreeze($this->alice);

    Balance::withdraw(from: $this->alice, amount: 100);

    expect($this->alice->balanceAmount())->toBe(9_900);
});

it('clears the reason when unfreezing', function () {
    Balance::freeze($this->alice, reason: 'aml-review');

    $account = Balance::unfreeze($this->alice);

    expect($account->isFrozen())->toBeFalse()
        ->and($account->frozen_reason)->toBeNull();
});

it('freezes a named account without touching the others', function () {
    Balance::freeze($this->alice->balanceAccount('bonus'));

    expect($this->alice->balanceAccount('bonus')->fresh()->isFrozen())->toBeTrue()
        ->and($this->alice->balanceAccount()->fresh()->isFrozen())->toBeFalse();
});

it('dispatches freeze and unfreeze events', function () {
    Event::fake([AccountWasFrozen::class, AccountWasUnfrozen::class]);

    Balance::freeze($this->alice, reason: 'fraud');
    Balance::unfreeze($this->alice);

    Event::assertDispatched(
        AccountWasFrozen::class,
        fn (AccountWasFrozen $event) => $event->reason === 'fraud',
    );
    Event::assertDispatched(AccountWasUnfrozen::class);
});
