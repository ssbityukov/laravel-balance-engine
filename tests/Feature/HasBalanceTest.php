<?php

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

it('creates the default account on first access', function () {
    $user = User::create();

    $account = $user->balanceAccount();

    expect($account->name)->toBe('main')
        ->and($account->currency)->toBe('USD')
        ->and($account->purpose)->toBe(AccountPurpose::Available)
        ->and($account->owner_id)->toBe($user->getKey());
});

it('returns the same account on repeated access', function () {
    $user = User::create();

    expect($user->balanceAccount()->id)->toBe($user->balanceAccount()->id);
});

it('creates separate accounts per name and currency', function () {
    $user = User::create();

    $ids = [
        $user->balanceAccount()->id,
        $user->balanceAccount('bonus')->id,
        $user->balanceAccount('main', 'EUR')->id,
    ];

    expect(array_unique($ids))->toHaveCount(3);
});

it('reports zero amounts for a fresh owner', function () {
    $user = User::create();

    expect($user->balanceAmount())->toBe(0)
        ->and($user->balanceReserved())->toBe(0);
});

it('exposes accounts as a relation', function () {
    $user = User::create();
    $user->balanceAccount();
    $user->balanceAccount('bonus');

    expect($user->balanceAccounts()->count())->toBe(2);
});
