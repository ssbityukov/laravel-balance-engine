<?php

use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Entry;
use Bityukov\BalanceEngine\Models\Transaction;

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | Used whenever an operation is called without an explicit currency.
    |
    */

    'default_currency' => env('BALANCE_DEFAULT_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | "scale" is the number of minor units. It is used for formatting and input
    | validation only — the ledger itself never divides, every amount is stored
    | as a signed integer number of minor units.
    |
    */

    'currencies' => [
        'USD' => ['scale' => 2],
        'EUR' => ['scale' => 2],
        'JPY' => ['scale' => 0],
        'BTC' => ['scale' => 8],
    ],

    'table_prefix' => 'balance_',

    /*
    |--------------------------------------------------------------------------
    | Owner key type
    |--------------------------------------------------------------------------
    |
    | Column type for accounts.owner_id: int, uuid, ulid or string.
    | Use "string" only when account owners have mixed key types (e.g. a User
    | on uuid and a Team on bigint) — it is slower and needs explicit casts in
    | joins on PostgreSQL. Run `php artisan balance:install` to autodetect.
    |
    */

    'owner_key_type' => env('BALANCE_OWNER_KEY_TYPE', 'int'),

    'default_account_name' => 'main',

    /*
    |--------------------------------------------------------------------------
    | Transaction attempts
    |--------------------------------------------------------------------------
    |
    | Deadlock retries. Only effective when the operation is not nested inside
    | an outer transaction of your own (nesting uses savepoints, which are not
    | retried).
    |
    */

    'transaction_attempts' => 3,

    'models' => [
        'account' => Account::class,
        'transaction' => Transaction::class,
        'entry' => Entry::class,
    ],

];
