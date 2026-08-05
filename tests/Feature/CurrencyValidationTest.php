<?php

use Bityukov\BalanceEngine\Exceptions\UnsupportedCurrency;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Support\SystemAccounts;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

beforeEach(function () {
    $this->user = User::create();
});

it('refuses an unconfigured currency', function () {
    // Records are immutable, so an account opened in a currency that does not
    // exist is a typo you live with forever.
    expect(fn () => Balance::deposit(to: $this->user, amount: 1_000, currency: 'XYZ'))
        ->toThrow(UnsupportedCurrency::class);
});

it('refuses an unconfigured currency on a named account', function () {
    expect(fn () => $this->user->balanceAccount('bonus', 'XYZ'))
        ->toThrow(UnsupportedCurrency::class);
});

it('refuses an unconfigured currency on a system account', function () {
    expect(fn () => app(SystemAccounts::class)->external('XYZ'))
        ->toThrow(UnsupportedCurrency::class);
});

it('accepts every configured currency', function (string $currency) {
    Balance::deposit(to: $this->user->balanceAccount('main', $currency), amount: 1_000);

    expect($this->user->balanceAmount('main', $currency))->toBe(1_000);
})->with(['USD', 'EUR', 'JPY', 'BTC']);

it('is case sensitive rather than guessing', function () {
    expect(fn () => Balance::deposit(to: $this->user, amount: 1_000, currency: 'usd'))
        ->toThrow(UnsupportedCurrency::class);
});
