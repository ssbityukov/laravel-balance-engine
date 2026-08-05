<?php

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Facades\Balance;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Support\LedgerVerifier;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->alice = User::create(['name' => 'alice']);
    $this->bob = User::create(['name' => 'bob']);

    Balance::deposit(to: $this->alice, amount: 10_000);
    Balance::transfer(from: $this->alice, to: $this->bob, amount: 4_000);
    $this->reservation = Balance::reserve(from: $this->bob, amount: 1_000);

    $this->verifier = app(LedgerVerifier::class);
});

/**
 * Forge a child transaction of the reservation with raw inserts. Nothing in the
 * public API can produce these states — that is the point of the verifier.
 */
function forgeChild(int $parentId, string $type, int $holdAccountId, int $otherAccountId, int $amount): void
{
    $id = DB::table('balance_transactions')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'type' => $type,
        'parent_id' => $parentId,
        'created_at' => now(),
    ]);

    DB::table('balance_entries')->insert([
        ['transaction_id' => $id, 'account_id' => $holdAccountId, 'amount' => -$amount, 'currency' => 'USD', 'balance_after' => 0, 'created_at' => now()],
        ['transaction_id' => $id, 'account_id' => $otherAccountId, 'amount' => $amount, 'currency' => 'USD', 'balance_after' => 0, 'created_at' => now()],
    ]);
}

it('reports no problems on a healthy ledger', function () {
    expect($this->verifier->verify())->toBe([]);
});

it('stays healthy after a partial capture and a release', function () {
    $this->reservation->capture(to: $this->alice, amount: 400);
    $this->reservation->release();

    expect($this->verifier->verify())->toBe([]);
});

it('exits zero on a healthy ledger', function () {
    $this->artisan('balance:verify')
        ->expectsOutputToContain('sound')
        ->assertExitCode(0);
});

it('detects a cached balance that drifted from the ledger', function () {
    $account = $this->alice->balanceAccount();

    DB::table('balance_accounts')->where('id', $account->id)->update(['balance' => 999]);

    $problems = $this->verifier->verify();

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->check)->toBe('account_balance')
        ->and($problems[0]->detail)->toContain((string) $account->id);
});

it('exits one when a balance drifted', function () {
    DB::table('balance_accounts')
        ->where('id', $this->alice->balanceAccount()->id)
        ->update(['balance' => 999]);

    $this->artisan('balance:verify')->assertExitCode(1);
});

it('detects a global sum that is not zero', function () {
    DB::table('balance_entries')->insert([
        'transaction_id' => DB::table('balance_transactions')->value('id'),
        'account_id' => $this->alice->balanceAccount()->id,
        'amount' => 500,
        'currency' => 'USD',
        'balance_after' => 0,
        'created_at' => now(),
    ]);

    $checks = collect($this->verifier->verify())->pluck('check');

    expect($checks)->toContain('global_sum');
});

it('detects a reservation whose children drew more than it held', function () {
    forgeChild(
        parentId: $this->reservation->transaction->id,
        type: TransactionType::Release->value,
        holdAccountId: $this->reservation->holdAccount()->id,
        otherAccountId: $this->bob->balanceAccount()->id,
        amount: 1_500,
    );

    $checks = collect($this->verifier->verify())->pluck('check');

    expect($checks)->toContain('reservation_settlement');
});

it('detects a capture with no parent reservation', function () {
    DB::table('balance_transactions')->insert([
        'uuid' => (string) Str::uuid(),
        'type' => TransactionType::Capture->value,
        'parent_id' => null,
        'created_at' => now(),
    ]);

    $checks = collect($this->verifier->verify())->pluck('check');

    expect($checks)->toContain('reservation_parent');
});

it('detects a capture parented to something that is not a reservation', function () {
    $deposit = DB::table('balance_transactions')
        ->where('type', TransactionType::Deposit->value)
        ->value('id');

    DB::table('balance_transactions')->insert([
        'uuid' => (string) Str::uuid(),
        'type' => TransactionType::Capture->value,
        'parent_id' => $deposit,
        'created_at' => now(),
    ]);

    $checks = collect($this->verifier->verify())->pluck('check');

    expect($checks)->toContain('reservation_parent');
});

it('detects a negative hold account', function () {
    $hold = Account::where('purpose', AccountPurpose::Hold)->first();

    DB::table('balance_accounts')->where('id', $hold->id)->update(['balance' => -1]);

    $checks = collect($this->verifier->verify())->pluck('check');

    expect($checks)->toContain('negative_hold');
});

it('detects an owner_type that no longer resolves to a class', function () {
    DB::table('balance_accounts')
        ->where('id', $this->alice->balanceAccount()->id)
        ->update(['owner_type' => 'App\\Models\\Deleted']);

    $checks = collect($this->verifier->verify())->pluck('check');

    expect($checks)->toContain('morph_map');
});

it('accepts owner types registered in the morph map', function () {
    // enforceMorphMap writes static state on Relation that outlives the
    // application instance, so it is put back before the next test runs.
    // Nothing fails without this today only because every fixture morphs to
    // User; a second fixture model would break tests far from here.
    Relation::enforceMorphMap(['user' => User::class]);

    try {
        DB::table('balance_accounts')
            ->where('id', $this->alice->balanceAccount()->id)
            ->update(['owner_type' => 'user']);

        $checks = collect($this->verifier->verify())->pluck('check');

        expect($checks)->not->toContain('morph_map');
    } finally {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }
});

it('narrows verification to a single account', function () {
    $drifted = $this->alice->balanceAccount();

    DB::table('balance_accounts')->where('id', $drifted->id)->update(['balance' => 999]);

    expect($this->verifier->verify($this->bob->balanceAccount()->id))->toBe([])
        ->and($this->verifier->verify($drifted->id))->toHaveCount(1);
});
