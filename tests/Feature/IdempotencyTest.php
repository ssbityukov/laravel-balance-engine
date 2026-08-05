<?php

use Bityukov\BalanceEngine\Exceptions\IdempotencyKeyReused;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Ledger\IdempotencyGuard;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;
use Bityukov\BalanceEngine\Tests\Fixtures\User;

beforeEach(function () {
    $this->alice = User::create(['name' => 'alice']);
    $this->bob = User::create(['name' => 'bob']);
});

it('credits only once when a deposit is replayed', function () {
    Balance::deposit(to: $this->alice, amount: 10_000, idempotencyKey: 'stripe:pi_1');
    Balance::deposit(to: $this->alice, amount: 10_000, idempotencyKey: 'stripe:pi_1');

    expect($this->alice->balanceAmount())->toBe(10_000)
        ->and(Transaction::count())->toBe(1)
        ->and(Entry::count())->toBe(2);
});

it('returns the original transaction on replay', function () {
    $first = Balance::deposit(to: $this->alice, amount: 10_000, idempotencyKey: 'stripe:pi_1');
    $second = Balance::deposit(to: $this->alice, amount: 10_000, idempotencyKey: 'stripe:pi_1');

    expect($second->id)->toBe($first->id)
        ->and($second->uuid)->toBe($first->uuid);
});

it('throws when the same key is reused with a different amount', function () {
    Balance::deposit(to: $this->alice, amount: 10_000, idempotencyKey: 'stripe:pi_1');

    expect(fn () => Balance::deposit(to: $this->alice, amount: 20_000, idempotencyKey: 'stripe:pi_1'))
        ->toThrow(IdempotencyKeyReused::class);

    expect($this->alice->balanceAmount())->toBe(10_000);
});

it('throws when the same key is reused for a different account', function () {
    Balance::deposit(to: $this->alice, amount: 10_000, idempotencyKey: 'stripe:pi_1');

    expect(fn () => Balance::deposit(to: $this->bob, amount: 10_000, idempotencyKey: 'stripe:pi_1'))
        ->toThrow(IdempotencyKeyReused::class);

    expect($this->bob->balanceAmount())->toBe(0);
});

it('throws when the same key is reused for a different operation type', function () {
    Balance::deposit(to: $this->alice, amount: 10_000, idempotencyKey: 'op:1');

    expect(fn () => Balance::withdraw(from: $this->alice, amount: 10_000, idempotencyKey: 'op:1'))
        ->toThrow(IdempotencyKeyReused::class);
});

it('replays withdrawals', function () {
    Balance::deposit(to: $this->alice, amount: 10_000);

    Balance::withdraw(from: $this->alice, amount: 3_000, idempotencyKey: 'payout:7');
    Balance::withdraw(from: $this->alice, amount: 3_000, idempotencyKey: 'payout:7');

    expect($this->alice->balanceAmount())->toBe(7_000);
});

it('replays transfers', function () {
    Balance::deposit(to: $this->alice, amount: 10_000);

    Balance::transfer(from: $this->alice, to: $this->bob, amount: 2_000, idempotencyKey: 'settle:9');
    Balance::transfer(from: $this->alice, to: $this->bob, amount: 2_000, idempotencyKey: 'settle:9');

    expect($this->alice->balanceAmount())->toBe(8_000)
        ->and($this->bob->balanceAmount())->toBe(2_000);
});

it('treats distinct keys as distinct operations', function () {
    Balance::deposit(to: $this->alice, amount: 100, idempotencyKey: 'a');
    Balance::deposit(to: $this->alice, amount: 100, idempotencyKey: 'b');

    expect($this->alice->balanceAmount())->toBe(200)
        ->and(Transaction::whereNotNull('idempotency_key')->count())->toBe(2);
});

it('does not deduplicate when no key is given', function () {
    Balance::deposit(to: $this->alice, amount: 100);
    Balance::deposit(to: $this->alice, amount: 100);

    expect($this->alice->balanceAmount())->toBe(200);
});

it('survives a race on the same key', function () {
    // Simulates two workers reaching the insert simultaneously: the second one
    // loses the unique index and must fall back to the stored transaction.
    $first = Balance::deposit(to: $this->alice, amount: 100, idempotencyKey: 'race:1');

    $guard = app(IdempotencyGuard::class);
    $fingerprint = $first->fresh()->idempotency_fingerprint;

    expect($guard->replayOrFail('race:1', $fingerprint)->id)->toBe($first->id);
});
