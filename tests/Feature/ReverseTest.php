<?php

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\TransactionReversed;
use Bityukov\BalanceEngine\Exceptions\InsufficientFunds;
use Bityukov\BalanceEngine\Exceptions\TransactionAlreadyReversed;
use Bityukov\BalanceEngine\Exceptions\TransactionNotReversible;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->alice = User::create(['name' => 'alice']);
    $this->bob = User::create(['name' => 'bob']);
});

it('undoes a deposit', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 10_000);

    Balance::reverse($deposit);

    expect($this->alice->balanceAmount())->toBe(0);
});

it('undoes a transfer in both directions', function () {
    Balance::deposit(to: $this->alice, amount: 10_000);
    $transfer = Balance::transfer(from: $this->alice, to: $this->bob, amount: 4_000);

    Balance::reverse($transfer);

    expect($this->alice->balanceAmount())->toBe(10_000)
        ->and($this->bob->balanceAmount())->toBe(0);
});

it('creates a mirrored transaction rather than deleting entries', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 10_000);

    $reversal = Balance::reverse($deposit);

    expect($reversal->type)->toBe(TransactionType::Reversal)
        ->and($reversal->reverses_id)->toBe($deposit->id)
        ->and($reversal->entries)->toHaveCount(2)
        ->and(Entry::count())->toBe(4)
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('mirrors each amount with the opposite sign', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 10_000);

    $reversal = Balance::reverse($deposit);

    $original = $deposit->entries->keyBy('account_id');
    $mirrored = $reversal->entries->keyBy('account_id');

    foreach ($original as $accountId => $entry) {
        expect($mirrored[$accountId]->amount)->toBe(-$entry->amount);
    }
});

it('links the reversal back from the original', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 100);

    $reversal = Balance::reverse($deposit);

    expect($deposit->fresh()->reversal->is($reversal))->toBeTrue();
});

it('refuses to reverse the same transaction twice', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 10_000);

    Balance::reverse($deposit);

    expect(fn () => Balance::reverse($deposit))
        ->toThrow(TransactionAlreadyReversed::class);
});

it('refuses to reverse reservation bookkeeping transactions', function (string $method) {
    Balance::deposit(to: $this->alice, amount: 10_000);
    $reservation = Balance::reserve(from: $this->alice, amount: 3_000);

    $transaction = match ($method) {
        'reserve' => $reservation->transaction,
        'capture' => $reservation->capture(to: $this->bob, amount: 1_000),
        'release' => $reservation->release(amount: 1_000),
        default => throw new InvalidArgumentException("No arm for dataset value [{$method}]."),
    };

    expect(fn () => Balance::reverse($transaction))
        ->toThrow(TransactionNotReversible::class);
})->with(['reserve', 'capture', 'release']);

it('refuses to reverse when the money is already spent', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 10_000);
    Balance::transfer(from: $this->alice, to: $this->bob, amount: 10_000);

    expect(fn () => Balance::reverse($deposit))
        ->toThrow(InsufficientFunds::class);

    expect($this->alice->balanceAmount())->toBe(0)
        ->and($this->bob->balanceAmount())->toBe(10_000);
});

it('can reverse a reversal', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 10_000);
    $reversal = Balance::reverse($deposit);

    Balance::reverse($reversal);

    expect($this->alice->balanceAmount())->toBe(10_000);
});

it('stores meta on the reversal', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 100);

    $reversal = Balance::reverse($deposit, meta: ['reason' => 'chargeback'])->fresh();

    expect($reversal->meta)->toBe(['reason' => 'chargeback']);
});

it('dispatches TransactionReversed', function () {
    $deposit = Balance::deposit(to: $this->alice, amount: 100);

    Event::fake([TransactionReversed::class]);

    Balance::reverse($deposit);

    Event::assertDispatched(
        TransactionReversed::class,
        fn (TransactionReversed $event) => $event->original->id === $deposit->id,
    );
});
