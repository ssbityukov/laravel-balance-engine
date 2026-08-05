<?php

use Bityukov\BalanceEngine\Enums\ReservationStatus;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Support\LedgerVerifier;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

beforeEach(function () {
    $this->buyer = User::create(['name' => 'buyer']);
    $this->seller = User::create(['name' => 'seller']);

    Balance::deposit(to: $this->buyer, amount: 10_000);
});

it('releases an expired reservation back to the owner', function () {
    $reservation = Balance::reserve(
        from: $this->buyer,
        amount: 3_000,
        expiresAt: now()->addMinute(),
    );

    $this->travel(2)->minutes();

    expect($reservation->status())->toBe(ReservationStatus::Expired);

    $this->artisan('balance:expire-reservations')->assertExitCode(0);

    expect($this->buyer->balanceAmount())->toBe(10_000)
        ->and($this->buyer->balanceReserved())->toBe(0)
        ->and($reservation->remaining())->toBe(0)
        // Not Expired: expired means "elapsed and still holding money", and the
        // money is no longer held. The elapsing is still recoverable from
        // expires_at and the release child.
        ->and($reservation->status())->toBe(ReservationStatus::Released);
});

it('leaves reservations that have not expired alone', function () {
    $reservation = Balance::reserve(
        from: $this->buyer,
        amount: 3_000,
        expiresAt: now()->addHour(),
    );

    $this->artisan('balance:expire-reservations')->assertExitCode(0);

    expect($reservation->status())->toBe(ReservationStatus::Open)
        ->and($this->buyer->balanceReserved())->toBe(3_000);
});

it('leaves reservations without an expiry alone', function () {
    $reservation = Balance::reserve(from: $this->buyer, amount: 3_000);

    $this->travel(100)->years();

    $this->artisan('balance:expire-reservations')->assertExitCode(0);

    expect($reservation->status())->toBe(ReservationStatus::Open)
        ->and($this->buyer->balanceReserved())->toBe(3_000);
});

it('releases only the uncaptured remainder', function () {
    $reservation = Balance::reserve(
        from: $this->buyer,
        amount: 3_000,
        expiresAt: now()->addMinute(),
    );

    $reservation->capture(to: $this->seller, amount: 1_000);

    $this->travel(2)->minutes();

    $this->artisan('balance:expire-reservations')->assertExitCode(0);

    expect($this->seller->balanceAmount())->toBe(1_000)
        ->and($this->buyer->balanceAmount())->toBe(9_000)
        ->and($this->buyer->balanceReserved())->toBe(0)
        // Captured rather than Released: something was captured before expiry.
        ->and($reservation->status())->toBe(ReservationStatus::Captured);
});

it('ignores an already settled reservation', function () {
    $reservation = Balance::reserve(
        from: $this->buyer,
        amount: 3_000,
        expiresAt: now()->addMinute(),
    );

    $reservation->release();

    $this->travel(2)->minutes();

    $this->artisan('balance:expire-reservations')
        ->expectsOutputToContain('0')
        ->assertExitCode(0);
});

it('expires only the unsettled one when two share a hold account', function () {
    $settled = Balance::reserve(from: $this->buyer, amount: 1_000, expiresAt: now()->addMinute());
    $open = Balance::reserve(from: $this->buyer, amount: 2_000, expiresAt: now()->addMinute());

    $settled->release();

    $this->travel(2)->minutes();

    // The remaining subquery has to be correlated to each reserve. Summing the
    // hold account instead would still see 2000 sitting there and report both.
    $this->artisan('balance:expire-reservations')
        ->expectsOutputToContain('1')
        ->assertExitCode(0);

    expect($settled->released())->toBe(1_000)
        ->and($open->released())->toBe(2_000)
        ->and($this->buyer->balanceReserved())->toBe(0);
});

it('keeps the ledger balanced and verifying clean', function () {
    Balance::reserve(from: $this->buyer, amount: 3_000, expiresAt: now()->addMinute());

    $this->travel(2)->minutes();

    $this->artisan('balance:expire-reservations');

    expect((int) Entry::sum('amount'))->toBe(0)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('reports the number of reservations it expired', function () {
    Balance::reserve(from: $this->buyer, amount: 1_000, expiresAt: now()->addMinute());
    Balance::reserve(from: $this->buyer, amount: 1_000, expiresAt: now()->addMinute());

    $this->travel(2)->minutes();

    $this->artisan('balance:expire-reservations')
        ->expectsOutputToContain('2')
        ->assertExitCode(0);
});
