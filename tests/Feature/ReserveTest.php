<?php

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Enums\ReservationStatus;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\Reserved;
use Bityukov\BalanceEngine\Exceptions\CannotReserveSystemAccount;
use Bityukov\BalanceEngine\Exceptions\InsufficientFunds;
use Bityukov\BalanceEngine\Exceptions\InvalidAmount;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::create();

    Balance::deposit(to: $this->user, amount: 10_000);
});

it('moves money from the available account to the hold account', function () {
    Balance::reserve(from: $this->user, amount: 3_000);

    expect($this->user->balanceAmount())->toBe(7_000)
        ->and($this->user->balanceReserved())->toBe(3_000);
});

it('records the movement as real ledger entries', function () {
    $reservation = Balance::reserve(from: $this->user, amount: 3_000);

    expect($reservation->transaction->entries)->toHaveCount(2)
        ->and($reservation->transaction->type)->toBe(TransactionType::Reserve)
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('creates the hold account with the same name and currency', function () {
    $reservation = Balance::reserve(from: $this->user, amount: 3_000);

    expect($reservation->holdAccount()->purpose)->toBe(AccountPurpose::Hold)
        ->and($reservation->holdAccount()->name)->toBe('main')
        ->and($reservation->holdAccount()->currency)->toBe('USD')
        ->and($reservation->account()->purpose)->toBe(AccountPurpose::Available);
});

it('reuses one hold account across reservations', function () {
    $first = Balance::reserve(from: $this->user, amount: 1_000);
    $second = Balance::reserve(from: $this->user, amount: 2_000);

    expect($second->holdAccount()->id)->toBe($first->holdAccount()->id)
        ->and($this->user->balanceReserved())->toBe(3_000);
});

it('opens the reservation with nothing captured or released', function () {
    $reservation = Balance::reserve(from: $this->user, amount: 3_000);

    expect($reservation->status())->toBe(ReservationStatus::Open)
        ->and($reservation->isOpen())->toBeTrue()
        ->and($reservation->amount())->toBe(3_000)
        ->and($reservation->captured())->toBe(0)
        ->and($reservation->released())->toBe(0)
        ->and($reservation->remaining())->toBe(3_000);
});

it('derives remaining independently for two reservations sharing a hold account', function () {
    $first = Balance::reserve(from: $this->user, amount: 1_000);
    $second = Balance::reserve(from: $this->user, amount: 2_000);

    // Both live on the same hold account, so remaining() must be scoped to the
    // reserve transaction rather than to the account balance.
    expect($first->remaining())->toBe(1_000)
        ->and($second->remaining())->toBe(2_000)
        ->and($this->user->balanceReserved())->toBe(3_000);
});

it('stores the expiry, reference and meta', function () {
    $order = User::create();
    $expiry = now()->addMinutes(15);

    $reservation = Balance::reserve(
        from: $this->user,
        amount: 3_000,
        expiresAt: $expiry,
        reference: $order,
        meta: ['checkout' => 'abc'],
    );

    expect($reservation->expiresAt()->timestamp)->toBe($expiry->timestamp)
        ->and($reservation->transaction->fresh()->reference->is($order))->toBeTrue()
        ->and($reservation->transaction->fresh()->meta)->toBe(['checkout' => 'abc']);
});

it('reports an elapsed reservation as expired rather than open', function () {
    $reservation = Balance::reserve(
        from: $this->user,
        amount: 3_000,
        expiresAt: now()->addMinutes(15),
    );

    expect($reservation->isOpen())->toBeTrue();

    $this->travel(16)->minutes();

    expect($reservation->isExpired())->toBeTrue()
        ->and($reservation->isOpen())->toBeFalse()
        ->and($reservation->status())->toBe(ReservationStatus::Expired)
        ->and($reservation->remaining())->toBe(3_000);
});

it('never expires a reservation created without an expiry', function () {
    $reservation = Balance::reserve(from: $this->user, amount: 3_000);

    $this->travel(100)->years();

    expect($reservation->expiresAt())->toBeNull()
        ->and($reservation->isExpired())->toBeFalse()
        ->and($reservation->isOpen())->toBeTrue();
});

it('refuses to reserve more than is available', function () {
    expect(fn () => Balance::reserve(from: $this->user, amount: 10_001))
        ->toThrow(InsufficientFunds::class);

    expect($this->user->balanceAmount())->toBe(10_000)
        ->and(Transaction::where('type', TransactionType::Reserve)->count())->toBe(0);
});

it('refuses a zero or negative amount', function (int $amount) {
    expect(fn () => Balance::reserve(from: $this->user, amount: $amount))
        ->toThrow(InvalidAmount::class);
})->with([0, -1]);

it('refuses to reserve from a system account', function () {
    $external = app(SystemAccounts::class)->external('USD');

    expect(fn () => Balance::reserve(from: $external, amount: 100))
        ->toThrow(CannotReserveSystemAccount::class);
});

it('is idempotent under a repeated key', function () {
    $first = Balance::reserve(from: $this->user, amount: 3_000, idempotencyKey: 'checkout:1');
    $second = Balance::reserve(from: $this->user, amount: 3_000, idempotencyKey: 'checkout:1');

    expect($second->transaction->id)->toBe($first->transaction->id)
        ->and($this->user->balanceReserved())->toBe(3_000)
        ->and(Transaction::where('type', TransactionType::Reserve)->count())->toBe(1);
});

it('dispatches Reserved', function () {
    Event::fake([Reserved::class]);

    Balance::reserve(from: $this->user, amount: 3_000);

    Event::assertDispatched(Reserved::class, fn (Reserved $event) => $event->reservation->amount() === 3_000);
});
