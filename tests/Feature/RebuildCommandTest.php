<?php

use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Support\LedgerVerifier;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->alice = User::create(['name' => 'alice']);
    $this->bob = User::create(['name' => 'bob']);

    Balance::deposit(to: $this->alice, amount: 10_000);
    Balance::transfer(from: $this->alice, to: $this->bob, amount: 4_000);
});

it('restores a drifted balance from the ledger', function () {
    $account = $this->alice->balanceAccount();

    DB::table('balance_accounts')->where('id', $account->id)->update(['balance' => 42]);

    $this->artisan('balance:rebuild')->assertExitCode(0);

    expect($account->fresh()->balance)->toBe(6_000);
});

it('leaves a healthy ledger verifying clean', function () {
    $this->artisan('balance:rebuild')->assertExitCode(0);

    expect(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('reports how many accounts it changed', function () {
    DB::table('balance_accounts')
        ->where('id', $this->alice->balanceAccount()->id)
        ->update(['balance' => 42]);

    $this->artisan('balance:rebuild')
        ->expectsOutputToContain('1')
        ->assertExitCode(0);
});

it('rebuilds a single account when asked', function () {
    $alice = $this->alice->balanceAccount();
    $bob = $this->bob->balanceAccount();

    DB::table('balance_accounts')->whereIn('id', [$alice->id, $bob->id])->update(['balance' => 42]);

    $this->artisan('balance:rebuild', ['--account' => $alice->id])->assertExitCode(0);

    expect($alice->fresh()->balance)->toBe(6_000)
        ->and($bob->fresh()->balance)->toBe(42);
});

it('sets an account with no entries to zero', function () {
    $empty = $this->alice->balanceAccount('bonus');

    DB::table('balance_accounts')->where('id', $empty->id)->update(['balance' => 500]);

    $this->artisan('balance:rebuild')->assertExitCode(0);

    expect($empty->fresh()->balance)->toBe(0);
});
