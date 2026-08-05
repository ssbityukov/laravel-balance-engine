<?php

use Bityukov\BalanceEngine\Exceptions\BalanceException;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Ledger\ReservationQuery;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Support\LedgerVerifier;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

/**
 * Runs a long pseudo-random sequence of operations and then asserts the
 * invariants. The seed is fixed so a failure is reproducible; change it only
 * when you want to hunt for new sequences.
 */
it('keeps every invariant across 500 random operations', function () {
    mt_srand(20260731);

    $users = collect(range(1, 10))
        ->map(fn (int $i) => User::create(['name' => "user{$i}"]))
        ->all();

    // Everyone starts funded so most operations are legal rather than rejected.
    foreach ($users as $user) {
        Balance::deposit(to: $user, amount: 100_000);
    }

    $performed = 0;
    $rejected = 0;

    for ($i = 0; $i < 500; $i++) {
        $user = $users[mt_rand(0, 9)];
        $other = $users[mt_rand(0, 9)];
        $amount = mt_rand(1, 5_000);

        try {
            match (mt_rand(1, 7)) {
                1 => Balance::deposit(to: $user, amount: $amount),
                2 => Balance::withdraw(from: $user, amount: $amount),
                3 => Balance::transfer(from: $user, to: $other, amount: $amount),
                4 => Balance::reserve(from: $user, amount: $amount),
                5 => Balance::reserve(from: $user, amount: $amount, expiresAt: now()->addMinutes(mt_rand(1, 30))),
                6 => captureRandomReservation($other),
                7 => releaseRandomReservation(),
            };

            $performed++;
        } catch (BalanceException) {
            // Rejections are legitimate outcomes: an overdraft, a self-transfer,
            // a closed reservation. The ledger must stay sound either way.
            $rejected++;
        }

        // Time moves forward so some reservations elapse mid-run and the expiry
        // paths get exercised rather than only the happy ones.
        if ($i % 50 === 49) {
            $this->travel(10)->minutes();
            $this->artisan('balance:expire-reservations');
        }
    }

    expect($performed)->toBeGreaterThan(200)
        ->and($rejected)->toBeGreaterThan(0);

    // Invariant 2: the whole system nets to zero.
    expect((int) Entry::sum('amount'))->toBe(0);

    // Invariants 3, 7, 8, 9 plus the morph map check.
    expect(app(LedgerVerifier::class)->verify())->toBe([]);

    // No account other than a system account may be negative.
    $negative = Account::query()
        ->whereNull('code')
        ->where('balance', '<', 0)
        ->count();

    expect($negative)->toBe(0);

    // Every entry's balance_after must match the running total of that account.
    foreach (Account::all() as $account) {
        $running = 0;

        $entries = Entry::query()->where('account_id', $account->getKey())->orderBy('id')->get();

        foreach ($entries as $entry) {
            $running += $entry->amount;

            expect($entry->balance_after)->toBe($running);
        }

        expect($account->balance)->toBe($running);
    }
});

/**
 * Collection::random() throws on an empty collection, and that would surface as
 * an InvalidArgumentException rather than a BalanceException — crashing the run
 * instead of counting as a rejection. Both helpers check first.
 */
function captureRandomReservation(User $destination): void
{
    $open = app(ReservationQuery::class)->open();

    if ($open->isEmpty()) {
        return;
    }

    $reservation = $open->random();

    $reservation->capture(to: $destination, amount: max(1, intdiv($reservation->remaining(), 2)));
}

function releaseRandomReservation(): void
{
    // Deliberately unsettled rather than open: an elapsed reservation can still
    // be released, and that path deserves exercising too.
    $unsettled = app(ReservationQuery::class)->unsettled();

    if ($unsettled->isEmpty()) {
        return;
    }

    $unsettled->random()->release();
}
