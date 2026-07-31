<?php

use Illuminate\Support\Facades\Schema;

it('creates all four ledger tables', function () {
    expect(Schema::hasTable('balance_accounts'))->toBeTrue()
        ->and(Schema::hasTable('balance_transactions'))->toBeTrue()
        ->and(Schema::hasTable('balance_entries'))->toBeTrue()
        ->and(Schema::hasTable('balance_reservations'))->toBeTrue();
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
        'reference_type', 'reference_id', 'reverses_id', 'meta', 'created_at',
    ]))->toBeTrue();
});

it('creates entry columns', function () {
    expect(Schema::hasColumns('balance_entries', [
        'id', 'transaction_id', 'account_id', 'amount', 'currency', 'balance_after', 'created_at',
    ]))->toBeTrue();
});

it('creates reservation columns', function () {
    expect(Schema::hasColumns('balance_reservations', [
        'id', 'transaction_id', 'account_id', 'hold_account_id', 'amount',
        'captured_amount', 'released_amount', 'status', 'expires_at',
        'reference_type', 'reference_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('honours the configured table prefix', function () {
    expect(config('balance.table_prefix'))->toBe('balance_');
});
