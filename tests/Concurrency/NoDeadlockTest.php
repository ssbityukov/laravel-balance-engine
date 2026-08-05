<?php

use Bityukov\BalanceEngine\Enums\ReservationStatus;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

it('survives transfers running in both directions at once', function () {
    $alice = User::create(['name' => 'alice']);
    $bob = User::create(['name' => 'bob']);

    Balance::deposit(to: $alice, amount: 100_000);
    Balance::deposit(to: $bob, amount: 100_000);

    $aliceAccount = $alice->balanceAccount();
    $bobAccount = $bob->balanceAccount();

    $total = $aliceAccount->balance + $bobAccount->balance;

    // Two children push money in opposite directions between the same pair of
    // accounts. Without ordered lock acquisition this deadlocks within a few
    // iterations.
    $codes = $this->fork(2, function (int $index) use ($aliceAccount, $bobAccount): int {
        [$from, $to] = $index === 0
            ? [$aliceAccount, $bobAccount]
            : [$bobAccount, $aliceAccount];

        for ($i = 0; $i < 50; $i++) {
            try {
                Balance::transfer(from: $from->fresh(), to: $to->fresh(), amount: 100);
            } catch (Throwable) {
                // A deadlock that escaped the retry loop is a failure. Report it
                // through the exit code so the parent can see it.
                return 5;
            }
        }

        return 0;
    });

    expect($codes)->toBe([0, 0], 'A transfer failed under contention: '.implode(',', $codes));

    expect($aliceAccount->fresh()->balance + $bobAccount->fresh()->balance)->toBe($total)
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('keeps reservation accounting correct when captures race', function () {
    $buyer = User::create(['name' => 'buyer']);
    $seller = User::create(['name' => 'seller']);

    Balance::deposit(to: $buyer, amount: 10_000);

    $reservation = Balance::reserve(from: $buyer, amount: 1_000);
    $sellerAccount = $seller->balanceAccount();

    // Ten children try to capture the same 1000 at once. Exactly one may win.
    // This is the direct test of the guard that runs inside post() after the
    // hold account is locked: checking remaining() before the lock would let
    // every child read 1000 and all ten would draw the hold down.
    $codes = $this->fork(10, function () use ($reservation, $sellerAccount): int {
        try {
            $reservation->capture(to: $sellerAccount->fresh(), amount: 1_000);

            return 0;
        } catch (Throwable) {
            return 3;
        }
    });

    $won = count(array_keys($codes, 0, true));

    expect($won)->toBe(1, 'More than one child captured the same reservation: '.implode(',', $codes))
        ->and($sellerAccount->fresh()->balance)->toBe(1_000)
        ->and($buyer->balanceReserved())->toBe(0)
        ->and($reservation->captured())->toBe(1_000)
        ->and($reservation->status())->toBe(ReservationStatus::Captured)
        ->and((int) Entry::sum('amount'))->toBe(0);
});
