<?php

use Bityukov\BalanceEngine\Enums\ReservationStatus;
use Bityukov\BalanceEngine\Exceptions\InsufficientFunds;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

/**
 * Every code block in README.md and docs/ is copied from a test in this file.
 * Documentation that is not executed goes stale within a month.
 */
it('runs the readme quickstart verbatim', function () {
    $user = User::create();
    $alice = User::create(['name' => 'alice']);
    $bob = User::create(['name' => 'bob']);
    $buyer = User::create(['name' => 'buyer']);

    Balance::deposit(to: $alice, amount: 10_000);
    Balance::deposit(to: $buyer, amount: 10_000);

    Balance::deposit(to: $user, amount: 10_000);
    Balance::withdraw(from: $user, amount: 5_000);
    Balance::transfer(from: $alice, to: $bob, amount: 10_000);
    $reservation = Balance::reserve(from: $buyer, amount: 10_000);

    expect($user->balanceAmount())->toBe(5_000)
        ->and($bob->balanceAmount())->toBe(10_000)
        ->and($reservation->remaining())->toBe(10_000);
});

it('reads balances the way the readme shows', function () {
    $user = User::create();

    Balance::deposit(to: $user, amount: 10_000);
    Balance::reserve(from: $user, amount: 3_000);

    expect($user->balanceAmount())->toBe(7_000)
        ->and($user->balanceReserved())->toBe(3_000)
        ->and($user->balanceAccount()->currency)->toBe('USD');
});

it('runs the marketplace recipe', function () {
    $buyer = User::create(['name' => 'buyer']);
    $seller = User::create(['name' => 'seller']);

    Balance::deposit(to: $buyer, amount: 10_000);

    // Checkout: put the money aside without moving it to the seller yet.
    $reservation = Balance::reserve(
        from: $buyer,
        amount: 6_000,
        expiresAt: now()->addMinutes(30),
    );

    // Shipped: the seller gets paid.
    $reservation->capture(to: $seller);

    expect($buyer->balanceAmount())->toBe(4_000)
        ->and($buyer->balanceReserved())->toBe(0)
        ->and($seller->balanceAmount())->toBe(6_000)
        ->and($reservation->status())->toBe(ReservationStatus::Captured);
});

it('runs the cancelled order recipe', function () {
    $buyer = User::create(['name' => 'buyer']);

    Balance::deposit(to: $buyer, amount: 10_000);

    $reservation = Balance::reserve(from: $buyer, amount: 6_000);

    // Cancelled: give it back.
    $reservation->release();

    expect($buyer->balanceAmount())->toBe(10_000)
        ->and($buyer->balanceReserved())->toBe(0)
        ->and($reservation->status())->toBe(ReservationStatus::Released);
});

it('runs the marketplace recipe across two requests', function () {
    $buyer = User::create(['name' => 'buyer']);
    $seller = User::create(['name' => 'seller']);

    Balance::deposit(to: $buyer, amount: 10_000);

    // Checkout request: store the uuid on your order.
    $uuid = Balance::reserve(from: $buyer, amount: 6_000)->uuid();

    // Shipping request, later: load it back and pay the seller.
    Balance::reservation($uuid)->capture(to: $seller);

    expect($seller->balanceAmount())->toBe(6_000)
        ->and($buyer->balanceReserved())->toBe(0);
});

it('runs the platform fee recipe', function () {
    $buyer = User::create(['name' => 'buyer']);
    $seller = User::create(['name' => 'seller']);
    $platform = User::create(['name' => 'platform']);

    Balance::deposit(to: $buyer, amount: 10_000);

    $reservation = Balance::reserve(from: $buyer, amount: 10_000);

    // Two captures out of one reservation: the seller's share and the fee.
    $reservation->capture(to: $seller, amount: 9_000);
    $reservation->capture(to: $platform, amount: 1_000);

    expect($seller->balanceAmount())->toBe(9_000)
        ->and($platform->balanceAmount())->toBe(1_000)
        ->and($reservation->remaining())->toBe(0)
        ->and($reservation->status())->toBe(ReservationStatus::Captured);
});

it('runs the payment webhook recipe', function () {
    $user = User::create();

    $eventId = 'evt_1PxyzABC';

    // The gateway retries. Both calls return the same transaction and the money
    // lands once.
    $first = Balance::deposit(
        to: $user,
        amount: 10_000,
        idempotencyKey: "stripe:{$eventId}",
        meta: ['gateway' => 'stripe'],
    );

    $second = Balance::deposit(
        to: $user,
        amount: 10_000,
        idempotencyKey: "stripe:{$eventId}",
        meta: ['gateway' => 'stripe'],
    );

    expect($user->balanceAmount())->toBe(10_000)
        ->and($second->id)->toBe($first->id);
});

it('runs the bonus balance recipe', function () {
    $user = User::create();

    Balance::deposit(to: $user, amount: 10_000);
    Balance::deposit(to: $user->balanceAccount('bonus'), amount: 2_000);

    // Spend the bonus first, then whatever is left comes off the main account.
    $price = 3_000;
    $fromBonus = min($price, $user->balanceAmount('bonus'));

    if ($fromBonus > 0) {
        Balance::withdraw(from: $user->balanceAccount('bonus'), amount: $fromBonus);
    }

    if ($price > $fromBonus) {
        Balance::withdraw(from: $user, amount: $price - $fromBonus);
    }

    expect($user->balanceAmount('bonus'))->toBe(0)
        ->and($user->balanceAmount())->toBe(9_000);
});

it('runs the chargeback recipe', function () {
    $user = User::create();

    $deposit = Balance::deposit(to: $user, amount: 10_000);

    Balance::reverse($deposit, meta: ['reason' => 'chargeback']);

    expect($user->balanceAmount())->toBe(0)
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('refuses a chargeback once the money is gone', function () {
    $alice = User::create(['name' => 'alice']);
    $bob = User::create(['name' => 'bob']);

    $deposit = Balance::deposit(to: $alice, amount: 10_000);
    Balance::transfer(from: $alice, to: $bob, amount: 10_000);

    expect(fn () => Balance::reverse($deposit, meta: ['reason' => 'chargeback']))
        ->toThrow(InsufficientFunds::class);

    expect($alice->balanceAmount())->toBe(0);
});

it('runs the currency exchange recipe', function () {
    $user = User::create();

    Balance::deposit(to: $user, amount: 10_000);

    // There is no cross-currency transfer. An exchange is two operations
    // against the system account, at a rate your application decides.
    $rate = 0.9;
    $euros = (int) round(5_000 * $rate);

    Balance::withdraw(from: $user, amount: 5_000, currency: 'USD');
    Balance::deposit(to: $user->balanceAccount('main', 'EUR'), amount: $euros);

    expect($user->balanceAmount())->toBe(5_000)
        ->and($user->balanceAmount('main', 'EUR'))->toBe(4_500)
        ->and(app(SystemAccounts::class)->external('EUR')->fresh()->balance)->toBe(-4_500);
});

it('keeps the ledger provable after every documented example', function () {
    $user = User::create();

    Balance::deposit(to: $user, amount: 10_000);
    Balance::withdraw(from: $user, amount: 2_500);

    $reservation = Balance::reserve(from: $user, amount: 1_000);
    $reservation->release();

    $this->artisan('balance:verify')
        ->expectsOutputToContain('Ledger is sound')
        ->assertExitCode(0);
});
