<?php

use Bityukov\BalanceEngine\Enums\ReservationStatus;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Events\ReservationReleased;
use Bityukov\BalanceEngine\Exceptions\ReservationAmountExceeded;
use Bityukov\BalanceEngine\Exceptions\ReservationClosed;
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

it('returns the full amount to the owner', function () {
    $this->reservation->release();

    expect($this->buyer->balanceAmount())->toBe(10_000)
        ->and($this->buyer->balanceReserved())->toBe(0);
});

it('closes the reservation as released', function () {
    $this->reservation->release();

    expect($this->reservation->status())->toBe(ReservationStatus::Released)
        ->and($this->reservation->released())->toBe(3_000);
});

it('writes a release transaction parented to the reserve', function () {
    $transaction = $this->reservation->release();

    expect($transaction->type)->toBe(TransactionType::Release)
        ->and($transaction->entries)->toHaveCount(2)
        ->and($transaction->parent_id)->toBe($this->reservation->transaction->id)
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('keeps the reservation open after a partial release', function () {
    $this->reservation->release(amount: 1_000);

    expect($this->reservation->status())->toBe(ReservationStatus::Open)
        ->and($this->buyer->balanceAmount())->toBe(8_000)
        ->and($this->buyer->balanceReserved())->toBe(2_000);
});

it('closes as captured when money was both captured and released', function () {
    $this->reservation->capture(to: $this->seller, amount: 1_000);
    $this->reservation->release();

    expect($this->reservation->status())->toBe(ReservationStatus::Captured)
        ->and($this->reservation->captured())->toBe(1_000)
        ->and($this->reservation->released())->toBe(2_000)
        ->and($this->seller->balanceAmount())->toBe(1_000)
        ->and($this->buyer->balanceAmount())->toBe(9_000)
        ->and($this->buyer->balanceReserved())->toBe(0);
});

it('refuses to release more than remains', function () {
    expect(fn () => $this->reservation->release(amount: 3_001))
        ->toThrow(ReservationAmountExceeded::class);
});

it('refuses to release a closed reservation', function () {
    $this->reservation->release();

    expect(fn () => $this->reservation->release(amount: 1))
        ->toThrow(ReservationClosed::class);
});

it('releases an expired reservation without complaint', function () {
    $reservation = Balance::reserve(
        from: $this->buyer,
        amount: 1_000,
        expiresAt: now()->addMinute(),
    );

    $this->travel(2)->minutes();

    $reservation->release();

    expect($reservation->status())->toBe(ReservationStatus::Released)
        ->and($this->buyer->balanceReserved())->toBe(3_000);
});

it('dispatches ReservationReleased', function () {
    Event::fake([ReservationReleased::class]);

    $this->reservation->release(amount: 500);

    Event::assertDispatched(
        ReservationReleased::class,
        fn (ReservationReleased $event) => $event->amount === 500,
    );
});
