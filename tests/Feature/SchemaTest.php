<?php

use Illuminate\Support\Facades\Schema;

it('creates the three ledger tables', function () {
    expect(Schema::hasTable('balance_accounts'))->toBeTrue()
        ->and(Schema::hasTable('balance_transactions'))->toBeTrue()
        ->and(Schema::hasTable('balance_entries'))->toBeTrue();
});

it('does not create a reservations table', function () {
    // Reservation state is derived from transactions: a reserve transaction plus
    // its capture/release children. Nothing about a reservation is stored.
    expect(Schema::hasTable('balance_reservations'))->toBeFalse();
});

it('creates account columns', function () {
    expect(Schema::hasColumns('balance_accounts', [
        'id', 'owner_type', 'owner_id', 'code', 'name', 'purpose',
        'currency', 'balance', 'allows_negative', 'frozen_at', 'frozen_reason',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates transaction columns', function () {
    expect(Schema::hasColumns('balance_transactions', [
        'id', 'uuid', 'type', 'idempotency_key', 'idempotency_fingerprint',
        'reference_type', 'reference_id', 'reverses_id', 'parent_id', 'expires_at',
        'meta', 'created_at',
    ]))->toBeTrue();
});

it('creates entry columns', function () {
    expect(Schema::hasColumns('balance_entries', [
        'id', 'transaction_id', 'account_id', 'amount', 'currency', 'balance_after', 'created_at',
    ]))->toBeTrue();
});

it('honours the configured table prefix', function () {
    expect(config('balance.table_prefix'))->toBe('balance_');
});
