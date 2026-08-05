<?php

use Bityukov\BalanceEngine\Exceptions\CurrencyMismatch;
use Bityukov\BalanceEngine\Exceptions\UnresolvableOwner;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Support\AccountResolver;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->resolver = app(AccountResolver::class);
});

it('passes an account through unchanged', function () {
    $account = Account::create(['currency' => 'USD']);

    expect($this->resolver->resolve($account)->is($account))->toBeTrue();
});

it('rejects an account whose currency contradicts the requested one', function () {
    $account = Account::create(['currency' => 'USD']);

    expect(fn () => $this->resolver->resolve($account, 'EUR'))
        ->toThrow(CurrencyMismatch::class);
});

it('resolves an owner model to its default account', function () {
    $user = User::create();

    expect($this->resolver->resolve($user)->id)->toBe($user->balanceAccount()->id);
});

it('resolves an owner model to a named account in another currency', function () {
    $user = User::create();

    $account = $this->resolver->resolve($user, 'EUR', 'bonus');

    expect($account->name)->toBe('bonus')
        ->and($account->currency)->toBe('EUR');
});

it('refuses a model without the HasBalance trait', function () {
    $model = new class extends Model
    {
        protected $table = 'users';
    };

    expect(fn () => $this->resolver->resolve($model))
        ->toThrow(UnresolvableOwner::class);
});

it('creates the external system account once per currency', function () {
    $system = app(SystemAccounts::class);

    $first = $system->external('USD');
    $second = $system->external('USD');
    $euro = $system->external('EUR');

    expect($first->id)->toBe($second->id)
        ->and($first->allows_negative)->toBeTrue()
        ->and($first->code)->toBe('system:external')
        ->and($euro->id)->not->toBe($first->id);
});
