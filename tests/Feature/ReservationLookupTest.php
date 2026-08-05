<?php

use Bityukov\BalanceEngine\Enums\ReservationStatus;
use Bityukov\BalanceEngine\Exceptions\ReservationNotFound;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

beforeEach(function () {
    $this->buyer = User::create(['name' => 'buyer']);
    $this->seller = User::create(['name' => 'seller']);

    Balance::deposit(to: $this->buyer, amount: 10_000);
});

it('reloads a reservation from its uuid', function () {
    // The marketplace flow spans two requests: reserve at checkout, capture on
    // shipping. All the application stores is the uuid.
    $uuid = Balance::reserve(from: $this->buyer, amount: 3_000)->uuid();

    $reservation = Balance::reservation($uuid);

    expect($reservation->remaining())->toBe(3_000)
        ->and($reservation->status())->toBe(ReservationStatus::Open);
});

it('can capture through a reloaded reservation', function () {
    $uuid = Balance::reserve(from: $this->buyer, amount: 3_000)->uuid();

    Balance::reservation($uuid)->capture(to: $this->seller, amount: 1_000);

    expect($this->seller->balanceAmount())->toBe(1_000)
        ->and(Balance::reservation($uuid)->remaining())->toBe(2_000);
});

it('exposes the uuid rather than the sequential id', function () {
    $reservation = Balance::reserve(from: $this->buyer, amount: 3_000);

    expect($reservation->uuid())->toBeString()->toHaveLength(36)
        ->and($reservation->uuid())->toBe($reservation->transaction->uuid);
});

it('fails loudly on an unknown uuid', function () {
    expect(fn () => Balance::reservation('00000000-0000-0000-0000-000000000000'))
        ->toThrow(ReservationNotFound::class);
});

it('refuses to treat a non-reserve transaction as a reservation', function () {
    $deposit = Balance::deposit(to: $this->buyer, amount: 100);

    expect(fn () => Balance::reservation($deposit->uuid))
        ->toThrow(ReservationNotFound::class);
});
