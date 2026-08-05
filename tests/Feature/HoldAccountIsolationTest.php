<?php

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Exceptions\HoldAccountNotDirectlyUsable;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Support\LedgerVerifier;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->alice = User::create(['name' => 'alice']);
    $this->bob = User::create(['name' => 'bob']);

    Balance::deposit(to: $this->alice, amount: 10_000);
    Balance::deposit(to: $this->bob, amount: 10_000);

    $this->reservation = Balance::reserve(from: $this->bob, amount: 1_000);
    $this->hold = $this->reservation->holdAccount();
});

it('refuses a transfer into a hold account', function () {
    // Without this guard balanceReserved() reports money that no reservation
    // is holding, which is the number a marketplace gates spending on.
    expect(fn () => Balance::transfer(from: $this->alice, to: $this->hold, amount: 5_000))
        ->toThrow(HoldAccountNotDirectlyUsable::class);

    expect($this->bob->balanceReserved())->toBe(1_000);
});

it('refuses a deposit into a hold account', function () {
    expect(fn () => Balance::deposit(to: $this->hold, amount: 5_000))
        ->toThrow(HoldAccountNotDirectlyUsable::class);

    expect($this->bob->balanceReserved())->toBe(1_000);
});

it('refuses a withdrawal from a hold account', function () {
    expect(fn () => Balance::withdraw(from: $this->hold, amount: 500))
        ->toThrow(HoldAccountNotDirectlyUsable::class);

    expect($this->bob->balanceReserved())->toBe(1_000);
});

it('refuses a reservation taken from a hold account', function () {
    expect(fn () => Balance::reserve(from: $this->hold, amount: 500))
        ->toThrow(HoldAccountNotDirectlyUsable::class);

    expect($this->bob->balanceReserved())->toBe(1_000);
});

it('refuses a capture paid into a hold account', function () {
    expect(fn () => $this->reservation->capture(to: $this->hold, amount: 500))
        ->toThrow(HoldAccountNotDirectlyUsable::class);
});

it('still allows capture and release themselves', function () {
    $this->reservation->capture(to: $this->alice, amount: 400);
    $this->reservation->release();

    expect($this->bob->balanceReserved())->toBe(0)
        ->and($this->alice->balanceAmount())->toBe(10_400)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('detects money that reached a hold account outside a reservation chain', function () {
    // Forged, because the guards above now make this unreachable through the
    // API. The verifier still has to catch it: a database this old may predate
    // the guards, and nothing stops a stray manual UPDATE.
    $transaction = DB::table('balance_transactions')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'type' => TransactionType::Transfer->value,
        'created_at' => now(),
    ]);

    DB::table('balance_entries')->insert([
        ['transaction_id' => $transaction, 'account_id' => $this->hold->id, 'amount' => 500, 'currency' => 'USD', 'balance_after' => 0, 'created_at' => now()],
        ['transaction_id' => $transaction, 'account_id' => $this->alice->balanceAccount()->id, 'amount' => -500, 'currency' => 'USD', 'balance_after' => 0, 'created_at' => now()],
    ]);

    $checks = collect(app(LedgerVerifier::class)->verify())->pluck('check');

    expect($checks)->toContain('hold_isolation');
});

it('reports a healthy hold account as isolated', function () {
    $this->reservation->capture(to: $this->alice, amount: 400);

    $checks = collect(app(LedgerVerifier::class)->verify())->pluck('check');

    expect($checks)->not->toContain('hold_isolation');
});

it('leaves ordinary accounts alone', function () {
    $available = Account::where('purpose', AccountPurpose::Available)->firstOrFail();

    expect($available->purpose)->toBe(AccountPurpose::Available);

    Balance::transfer(from: $this->alice, to: $this->bob, amount: 100);

    expect($this->bob->balanceAmount())->toBe(9_100);
});
