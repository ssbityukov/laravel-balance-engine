<?php

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('resolves its table from config', function () {
    expect((new Account)->getTable())->toBe('balance_accounts');
});

it('casts purpose to an enum and balance to int', function () {
    $account = Account::create([
        'name' => 'main',
        'purpose' => AccountPurpose::Available,
        'currency' => 'USD',
    ]);

    expect($account->fresh()->purpose)->toBe(AccountPurpose::Available)
        ->and($account->fresh()->balance)->toBe(0);
});

it('reports frozen state', function () {
    $account = Account::create(['currency' => 'USD']);

    expect($account->isFrozen())->toBeFalse();

    $account->update(['frozen_at' => now()]);

    expect($account->fresh()->isFrozen())->toBeTrue();
});

it('belongs to a polymorphic owner', function () {
    $user = User::create();

    $account = Account::create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
        'currency' => 'USD',
    ]);

    expect($account->owner->is($user))->toBeTrue();
});

it('rejects duplicate accounts for the same owner, name, purpose and currency', function () {
    $user = User::create();

    $attributes = [
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
        'name' => 'main',
        'purpose' => AccountPurpose::Available,
        'currency' => 'USD',
    ];

    Account::create($attributes);

    expect(fn () => Account::create($attributes))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('scopes available and hold accounts', function () {
    Account::create(['currency' => 'USD', 'purpose' => AccountPurpose::Available]);
    Account::create(['currency' => 'USD', 'purpose' => AccountPurpose::Hold]);

    expect(Account::available()->count())->toBe(1)
        ->and(Account::hold()->count())->toBe(1);
});
