<?php

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\Deposited;
use Bityukov\BalanceEngine\Exceptions\InvalidAmount;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Bityukov\BalanceEngine\Tests\Fixtures\Order;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::create();
});

it('credits the owner account', function () {
    Balance::deposit(to: $this->user, amount: 10_000);

    expect($this->user->balanceAmount())->toBe(10_000);
});

it('writes exactly two entries summing to zero', function () {
    $transaction = Balance::deposit(to: $this->user, amount: 10_000);

    expect($transaction->entries)->toHaveCount(2)
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('debits the external system account', function () {
    Balance::deposit(to: $this->user, amount: 10_000);

    expect(app(SystemAccounts::class)->external('USD')->fresh()->balance)->toBe(-10_000);
});

it('records the transaction type', function () {
    expect(Balance::deposit(to: $this->user, amount: 100)->type)
        ->toBe(TransactionType::Deposit);
});

it('accumulates across deposits', function () {
    Balance::deposit(to: $this->user, amount: 100);
    Balance::deposit(to: $this->user, amount: 250);

    expect($this->user->balanceAmount())->toBe(350);
});

it('deposits into a named account and currency', function () {
    Balance::deposit(to: $this->user->balanceAccount('bonus', 'EUR'), amount: 500);

    expect($this->user->balanceAmount('bonus', 'EUR'))->toBe(500)
        ->and($this->user->balanceAmount())->toBe(0);
});

it('rejects a zero or negative amount', function (int $amount) {
    expect(fn () => Balance::deposit(to: $this->user, amount: $amount))
        ->toThrow(InvalidAmount::class);
})->with([0, -1, -500]);

it('stores reference and meta', function () {
    $order = Order::create();

    $transaction = Balance::deposit(
        to: $this->user,
        amount: 100,
        reference: $order,
        meta: ['gateway' => 'stripe'],
    )->fresh();

    expect($transaction->reference->is($order))->toBeTrue()
        ->and($transaction->meta)->toBe(['gateway' => 'stripe']);
});

it('stores the idempotency key and fingerprint', function () {
    $transaction = Balance::deposit(
        to: $this->user,
        amount: 100,
        idempotencyKey: 'stripe:pi_1',
    )->fresh();

    expect($transaction->idempotency_key)->toBe('stripe:pi_1')
        ->and($transaction->idempotency_fingerprint)->toHaveLength(64);
});

it('dispatches Deposited after commit', function () {
    Event::fake([Deposited::class]);

    Balance::deposit(to: $this->user, amount: 100);

    Event::assertDispatched(Deposited::class, function (Deposited $event) {
        // Compared as strings: owner_id round-trips through a column whose type
        // depends on balance.owner_key_type, so an integer key comes back as
        // "1" whenever that column is not an integer one.
        return $event->amount === 100
            && (string) $event->account->owner_id === (string) $this->user->getKey();
    });
});
