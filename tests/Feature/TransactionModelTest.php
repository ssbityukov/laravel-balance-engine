<?php

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;
use Bityukov\BalanceEngine\Tests\Fixtures\Order;
use Illuminate\Support\Carbon;

it('assigns a uuid on creation', function () {
    $transaction = Transaction::create(['type' => TransactionType::Deposit]);

    expect($transaction->uuid)->toBeString()->toHaveLength(36);
});

it('is addressable by uuid rather than by sequential id', function () {
    $transaction = Transaction::create(['type' => TransactionType::Deposit]);

    expect(Transaction::findByUuid($transaction->uuid)->id)->toBe($transaction->id)
        ->and(Transaction::findByUuid('00000000-0000-0000-0000-000000000000'))->toBeNull()
        ->and($transaction->getRouteKeyName())->toBe('uuid');
});

it('casts type and meta', function () {
    $transaction = Transaction::create([
        'type' => TransactionType::Transfer,
        'meta' => ['gateway' => 'stripe'],
    ])->fresh();

    expect($transaction->type)->toBe(TransactionType::Transfer)
        ->and($transaction->meta)->toBe(['gateway' => 'stripe']);
});

it('exposes its entries', function () {
    $account = Account::create(['currency' => 'USD']);
    $transaction = Transaction::create(['type' => TransactionType::Deposit]);

    Entry::create([
        'transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'amount' => 100,
        'currency' => 'USD',
        'balance_after' => 100,
    ]);

    expect($transaction->entries)->toHaveCount(1)
        ->and($transaction->entries->first()->account->is($account))->toBeTrue();
});

it('links capture and release children to their reserve parent', function () {
    $reserve = Transaction::create([
        'type' => TransactionType::Reserve,
        'expires_at' => now()->addMinutes(15),
    ]);

    $capture = Transaction::create([
        'type' => TransactionType::Capture,
        'parent_id' => $reserve->id,
    ]);

    $release = Transaction::create([
        'type' => TransactionType::Release,
        'parent_id' => $reserve->id,
    ]);

    expect($capture->parent->is($reserve))->toBeTrue()
        ->and($reserve->children->pluck('id')->all())->toBe([$capture->id, $release->id])
        ->and($reserve->fresh()->expires_at)->toBeInstanceOf(Carbon::class);
});

it('links a reversal to the transaction it reverses', function () {
    $original = Transaction::create(['type' => TransactionType::Deposit]);

    $reversal = Transaction::create([
        'type' => TransactionType::Reversal,
        'reverses_id' => $original->id,
    ]);

    expect($reversal->reverses->is($original))->toBeTrue()
        ->and($original->reversal->is($reversal))->toBeTrue();
});

it('stores a polymorphic reference', function () {
    $user = Order::create();

    $transaction = Transaction::create([
        'type' => TransactionType::Deposit,
        'reference_type' => $user->getMorphClass(),
        'reference_id' => $user->getKey(),
    ]);

    expect($transaction->reference->is($user))->toBeTrue();
});
