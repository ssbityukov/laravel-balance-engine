# Laravel Balance Engine

A double-entry ledger for Laravel that does not lose money under load.

## The problem

Almost every Laravel app starts here:

```php
$user->balance += 100;
$user->save();
```

With `balance = 100` and two simultaneous requests:

| | Request A | Request B |
|---|---|---|
| reads | 100 | 100 |
| writes | 200 | 200 |

Result: `200` instead of `300`. Money is gone, and there is no trace of it — no
record that a second request ever happened, nothing to reconcile against, no way
to find out later.

## The solution

```php
Balance::deposit(to: $user, amount: 10_000);
Balance::withdraw(from: $user, amount: 5_000);
Balance::transfer(from: $alice, to: $bob, amount: 10_000);
$reservation = Balance::reserve(from: $buyer, amount: 10_000);
```

Every one of those writes at least two ledger entries that sum to zero, takes
row locks in a deterministic order, and leaves a record that cannot be edited or
deleted.

## Prove it

```bash
php artisan balance:verify
```

```
Ledger is sound: every invariant holds.
```

And when something is wrong, it says so and exits `1`:

```
Ledger verification found 1 problem(s):

+-----------------+-------------------------------------------------------------------+
| Check           | Detail                                                            |
+-----------------+-------------------------------------------------------------------+
| account_balance | Account [1] has cached balance 9999 but its entries sum to 10000. |
+-----------------+-------------------------------------------------------------------+

Do not run balance:rebuild before finding out why this happened.
```

Seven invariants are checked: the global sum is zero, every cached balance
matches its entries, no reservation is drawn past zero, every capture and
release points at a reserve, nothing but a reservation chain has touched a hold
account, no hold account is negative, and every owner type still resolves.

## How this compares

|  | bavix/laravel-wallet | abivia/ledger | Balance Engine |
|---|---|---|---|
| deposit / withdraw / transfer as an API | yes | no, journal-level API | yes |
| holds with partial capture | holds, no partial | no | yes, via a hold account inside the ledger |
| strict double entry, `SUM = 0` | no | yes | yes |
| invariant check command, exit 1 | no | no | `balance:verify` |
| idempotency keys with fingerprint | no | no | yes |
| immutable records, corrections by reversal | no | partial | yes |
| race safety proven by tests | no | no | forked-process tests on MySQL and PostgreSQL |
| barrier to entry | low | high (chart of accounts) | low |

The niche is between the two: the double-entry strictness of `abivia/ledger`
with the developer experience of `bavix/laravel-wallet`. No chart of accounts,
an application-level API rather than a journal one, and an invariant you can
verify in CI.

## Installation

```bash
composer require ssbityukov/laravel-balance-engine
php artisan balance:install
php artisan migrate
```

`balance:install` detects whether your owner models use integer, UUID, ULID or
string keys and writes that into the published config, so the polymorphic
columns match your application.

## Register a morph map

This is not optional. The ledger stores owner types forever and its records are
immutable, so a raw `App\Models\User` string becomes unfixable the day you
rename or move the class:

```php
Relation::enforceMorphMap([
    'user' => User::class,
    'team' => Team::class,
]);
```

`balance:verify` fails on any owner type it cannot resolve, so a forgotten entry
here shows up as a failing check rather than as a mystery years later.

## Usage

Add the trait to anything that holds money:

```php
use Bityukov\BalanceEngine\Concerns\HasBalance;

class User extends Model
{
    use HasBalance;
}
```

Every member is prefixed with `balance`, because most models already have a
`balance` attribute of their own.

### Reading balances

```php
$user->balanceAmount();     // available, in minor units
$user->balanceReserved();   // held by open reservations
$user->balanceAccount();    // the Account model itself
```

Amounts are integers in minor units. There is no float anywhere in this package.

### Deposit and withdraw

```php
Balance::deposit(to: $user, amount: 10_000);
Balance::withdraw(from: $user, amount: 5_000);
```

Withdrawing more than is available throws `InsufficientFunds`, which carries the
account, what was requested and what was there.

### Transfer

```php
Balance::transfer(from: $alice, to: $bob, amount: 10_000);
```

Locks are always taken in ascending account id order, which is what stops two
transfers running in opposite directions between the same pair from deadlocking.

### Reservations

Money is moved onto a hold account, not marked with a flag:

```php
$reservation = Balance::reserve(
    from: $buyer,
    amount: 6_000,
    expiresAt: now()->addMinutes(30),
);

$reservation->capture(to: $seller);              // all of it
$reservation->capture(to: $seller, amount: 1_000); // part of it, stays open
$reservation->release();                          // hand the rest back
$reservation->release(amount: 2_000);             // hand part of it back

$reservation->remaining();  // int
$reservation->captured();   // int
$reservation->isOpen();     // bool
$reservation->status();     // ReservationStatus
```

Reserving and capturing usually happen in different requests. Keep the uuid and
load the reservation back when you need it:

```php
$uuid = Balance::reserve(from: $buyer, amount: 6_000)->uuid();

// Later, in another request:
Balance::reservation($uuid)->capture(to: $seller);
```

Hold accounts are not ordinary accounts. Money reaches one only through
`reserve()` and leaves it only through `capture()` and `release()`; depositing,
withdrawing or transferring against one throws `HoldAccountNotDirectlyUsable`.
Otherwise `balanceReserved()` would report money that no reservation was
holding.

The destination of a capture is mandatory. A default recipient would let money
drift onto a system account unnoticed.

Nothing about a reservation is stored: it *is* the reserve transaction, captures
and releases are its children, and every figure above is derived from the ledger.
Expired reservations are returned by a scheduled command:

```php
Schedule::command('balance:expire-reservations')->everyFiveMinutes();
```

### Reversal

Records are immutable. A mistake is corrected by writing its mirror image, never
by editing or deleting:

```php
Balance::reverse($transaction, meta: ['reason' => 'chargeback']);
```

A reversal is an ordinary ledger operation and obeys the ordinary rules, so
reversing a deposit whose money has already been spent fails with
`InsufficientFunds` rather than pushing an account negative.

### Freezing

```php
Balance::freeze($user, reason: 'aml-review');
Balance::unfreeze($user);
```

Freezing blocks debits only. Credits keep landing, which is the correct
semantics for fraud and AML work: stop payouts without losing money already in
flight.

### Idempotency

```php
Balance::deposit(
    to: $user,
    amount: 10_000,
    idempotencyKey: "stripe:{$event->id}",
);
```

A repeated call returns the stored transaction instead of moving money again.
Reusing a key for a *different* operation throws `IdempotencyKeyReused` rather
than silently replaying, because that would hide a bug in the caller.

Two independent mechanisms back this: a check before the transaction opens for
the ordinary retry, and a unique index for two workers racing on the same key.

### Named accounts and currencies

One owner can hold many accounts, separated by name and currency:

```php
$user->balanceAccount('bonus');
$user->balanceAccount('main', 'EUR');

Balance::deposit(to: $user->balanceAccount('bonus'), amount: 2_000);
```

There is no cross-currency transfer. An exchange is two operations at a rate
your application decides — see [docs/recipes.md](docs/recipes.md).

Currencies are validated against `balance.currencies`, so a typo throws
`UnsupportedCurrency` instead of quietly opening an account in a currency that
does not exist. Records are immutable, so that account would have been permanent.

## Production notes

**SQLite has no row-level locking.** `lockForUpdate()` is a silent no-op there,
so concurrent operations are not safe. Use MySQL or PostgreSQL in production;
the package logs a warning if it finds itself on SQLite in a production
environment.

**Retries do not work inside your own transaction.** Deadlock retries happen at
the `DB::transaction` level. If you wrap a balance call in an outer transaction
of your own, the inner one becomes a savepoint and the retry is lost. Call the
package outside your transaction where you can.

**Put the two commands to work:**

```php
Schedule::command('balance:expire-reservations')->everyFiveMinutes();
Schedule::command('balance:verify')->daily();
```

`balance:verify` exits `1` on any discrepancy, so it works as a monitoring
check. `balance:rebuild` can repair a drifted cached balance from the entries,
but find out why it drifted first — an automatic repair hides the bug that
caused it.

**References use an integer morph.** `reference_type` and `reference_id` are a
standard `nullableMorphs`, so for UUID-keyed reference models put the identifier
in `meta` instead.

## Documentation

- [docs/concepts.md](docs/concepts.md) — why the design is what it is
- [docs/recipes.md](docs/recipes.md) — marketplaces, platform fees, webhooks,
  bonus balances, chargebacks, currency exchange

Every code block in this README and in `docs/` is copied from a passing test in
`tests/Feature/DocumentationTest.php`.

## Testing

```bash
composer test              # the suite, on sqlite
composer analyse           # phpstan level 8
composer format            # pint

# concurrency needs a real database
DB_CONNECTION=mysql DB_DATABASE=balance_engine_test DB_USERNAME=root \
  composer test:concurrency
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
