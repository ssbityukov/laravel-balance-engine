<?php

use Bityukov\BalanceEngine\Exceptions\InsufficientFunds;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

const EXIT_OK = 0;
const EXIT_INSUFFICIENT = 3;
const EXIT_OTHER = 4;

it('lets exactly as many withdrawals succeed as there is money', function () {
    $user = User::create();

    Balance::deposit(to: $user, amount: 10);

    $account = $user->balanceAccount();

    $codes = $this->fork(20, function () use ($account): int {
        try {
            Balance::withdraw(from: $account->fresh(), amount: 1);

            return EXIT_OK;
        } catch (InsufficientFunds) {
            return EXIT_INSUFFICIENT;
        } catch (Throwable) {
            return EXIT_OTHER;
        }
    });

    $succeeded = count(array_keys($codes, EXIT_OK, true));
    $refused = count(array_keys($codes, EXIT_INSUFFICIENT, true));
    $broke = count(array_filter($codes, fn (int $c) => $c !== EXIT_OK && $c !== EXIT_INSUFFICIENT));

    expect($broke)->toBe(0, 'Some child died with an unexpected error: '.implode(',', $codes))
        ->and($succeeded)->toBe(10)
        ->and($refused)->toBe(10);

    expect($account->fresh()->balance)->toBe(0)
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('never lets a balance go negative under contention', function () {
    $user = User::create();

    Balance::deposit(to: $user, amount: 50);

    $account = $user->balanceAccount();

    $this->fork(30, function () use ($account): int {
        try {
            Balance::withdraw(from: $account->fresh(), amount: 3);

            return EXIT_OK;
        } catch (Throwable) {
            return EXIT_INSUFFICIENT;
        }
    });

    $balance = $account->fresh()->balance;

    expect($balance)->toBeGreaterThanOrEqual(0)
        ->and($balance)->toBeLessThan(3)
        ->and((int) Entry::sum('amount'))->toBe(0);
});

it('does not duplicate money when the same idempotency key races', function () {
    $user = User::create();

    $account = $user->balanceAccount();

    $this->fork(10, function () use ($account): int {
        try {
            Balance::deposit(to: $account->fresh(), amount: 1_000, idempotencyKey: 'race:same-key');

            return EXIT_OK;
        } catch (Throwable) {
            return EXIT_OTHER;
        }
    });

    expect($account->fresh()->balance)->toBe(1_000)
        ->and(Transaction::where('idempotency_key', 'race:same-key')->count())->toBe(1);
});
