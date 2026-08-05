<?php

use Bityukov\BalanceEngine\Enums\ReservationStatus;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\ReservationCaptured;
use Bityukov\BalanceEngine\Exceptions\ReservationAmountExceeded;
use Bityukov\BalanceEngine\Exceptions\ReservationClosed;
use Bityukov\BalanceEngine\Exceptions\ReservationExpired;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->buyer = User::create(['name' => 'buyer']);
    $this->seller = User::create(['name' => 'seller']);

    Balance::deposit(to: $this->buyer, amount: 10_000);

    $this->reservation = Balance::reserve(from: $this->buyer, amount: 3_000);
});

it('moves the full amount from hold to the destination', function () {
    $this->reservation->capture(to: $this->seller);

    expect($this->buyer->balanceReserved())->toBe(0)
        ->and($this->buyer->balanceAmount())->toBe(7_000)
        ->and($this->seller->balanceAmount())->toBe(3_000);
});

it('closes the reservation as captured', function () {
    $this->reservation->capture(to: $this->seller);

    expect($this->reservation->status())->toBe(ReservationStatus::Captured)
        ->and($this->reservation->captured())->toBe(3_000)
        ->and($this->reservation->remaining())->toBe(0)
        ->and($this->reservation->isOpen())->toBeFalse();
});

it('writes a capture transaction parented to the reserve', function () {
    $transaction = $this->reservation->capture(to: $this->seller);

    expect($transaction->type)->toBe(TransactionType::Capture)
        ->and($transaction->entries)->toHaveCount(2)
        ->and($transaction->parent_id)->toBe($this->reservation->transaction->id)
        ->and($transaction->parent->is($this->reservation->transaction))->toBeTrue()
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('keeps the reservation open after a partial capture', function () {
    $this->reservation->capture(to: $this->seller, amount: 1_000);

    expect($this->reservation->status())->toBe(ReservationStatus::Open)
        ->and($this->reservation->captured())->toBe(1_000)
        ->and($this->reservation->remaining())->toBe(2_000)
        ->and($this->buyer->balanceReserved())->toBe(2_000)
        ->and($this->seller->balanceAmount())->toBe(1_000);
});

it('allows capturing the remainder in a second call', function () {
    $this->reservation->capture(to: $this->seller, amount: 1_000);
    $this->reservation->capture(to: $this->seller);

    expect($this->seller->balanceAmount())->toBe(3_000)
        ->and($this->buyer->balanceReserved())->toBe(0)
        ->and($this->reservation->status())->toBe(ReservationStatus::Captured);
});

it('refuses to capture more than remains', function () {
    expect(fn () => $this->reservation->capture(to: $this->seller, amount: 3_001))
        ->toThrow(ReservationAmountExceeded::class);

    expect($this->buyer->balanceReserved())->toBe(3_000);
});

it('refuses to capture a closed reservation', function () {
    $this->reservation->capture(to: $this->seller);

    expect(fn () => $this->reservation->capture(to: $this->seller, amount: 1))
        ->toThrow(ReservationClosed::class);
});

it('refuses to capture an expired reservation', function () {
    $reservation = Balance::reserve(
        from: $this->buyer,
        amount: 1_000,
        expiresAt: now()->addMinute(),
    );

    $this->travel(2)->minutes();

    expect(fn () => $reservation->capture(to: $this->seller))
        ->toThrow(ReservationExpired::class);

    expect($this->buyer->balanceReserved())->toBe(4_000);
});

it('dispatches ReservationCaptured with the captured amount', function () {
    Event::fake([ReservationCaptured::class]);

    $this->reservation->capture(to: $this->seller, amount: 1_500);

    Event::assertDispatched(
        ReservationCaptured::class,
        fn (ReservationCaptured $event) => $event->amount === 1_500,
    );
});
