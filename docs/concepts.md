# Concepts

Why the package is built this way. Each section is a decision that could have
gone the other way, and the reason it did not.

## Double entry

Every operation writes at least two entries whose amounts sum to zero. A deposit
is not "add 100 to the user"; it is "take 100 from somewhere and give 100 to the
user". Money always comes from somewhere.

That single rule is what makes the ledger checkable. If the sum of every entry in
the system is not zero, something is wrong, and you can find out in one query
rather than by auditing application code. `balance:verify` is only possible
because of it.

The cost is that you cannot write a one-sided operation, even when you want to.
That is the point.

## Why the external account goes negative

Money entering the system comes from `system:external`, an account with
`allows_negative` set. After ten deposits of 100 it sits at -1000.

That is not a bug and not a rounding artefact. Its balance is the negative of
everything ever paid in from outside, so it is a running total of what the system
owes the outside world. The alternative — creating money out of nothing on a
deposit — would break the sum-to-zero invariant on the very first operation.

## The balance column is a cache

`balance_entries` is the truth. `accounts.balance` is a cached running total kept
because summing every entry on every read does not scale.

The two can only disagree if something wrote to the ledger incorrectly, which is
why `balance:verify` compares them and `balance:rebuild` can recompute the cache
from the entries. Rebuild is deliberately a separate manual command: an automatic
repair would paper over the bug that caused the drift.

## Reservations live on a hold account

A reservation could have been a `reserved` column on the account. It is not.
Reserved money is physically moved to a second account with `purpose = hold`
belonging to the same owner.

Two reasons. First, a column would sit outside the ledger, so the sum-to-zero
invariant would not cover it and frozen money would be invisible in the audit
trail. Second, a hold account is subject to every rule that protects any other
account: it cannot go negative, so a reservation cannot be drawn past what was
put into it, enforced by the database rather than by a check in application code.

## Reservation state is derived, never stored

There is no reservations table. A reservation *is* the reserve transaction;
captures and releases are its children through `parent_id`. `remaining`,
`captured`, `released` and `status` are computed from the ledger on demand.

The original design had a `balance_reservations` table with `captured_amount`,
`released_amount` and `status` columns. It was removed because it was the only
mutable table in a package whose central claim is that records are immutable — a
direct hole in the main invariant, and a second source of truth that could
disagree with the entries.

The price is that finding expired reservations needs an aggregate subquery
instead of `where('status', 'open')`. That is a fair trade for a status that
cannot lie.

Status follows from two numbers:

| Condition | Status |
|---|---|
| `remaining > 0`, not elapsed | `open` |
| `remaining > 0`, elapsed | `expired` |
| `remaining = 0`, `captured > 0` | `captured` |
| `remaining = 0`, `captured = 0` | `released` |

Note that `expired` is transient: it means "elapsed and still holding money". Once
`balance:expire-reservations` hands the remainder back, the reservation reads as
`released`. The fact that it elapsed is still recoverable from `expires_at` and
the release child.

## Records are immutable

`Transaction` and `Entry` throw on `update` and `delete`. There is no soft
delete, no correction in place, no admin panel edit.

A mistake is fixed by writing its mirror image with `Balance::reverse()`, which
leaves both the error and the correction in the history. An auditor can see what
happened and when it was fixed. A ledger you can edit is a ledger nobody can
trust, and the first time you need to prove a balance to somebody else, that
matters more than convenience.

Accounts stay mutable, because the cached balance has to move.

## Locks live in the database, not in Redis

Serialisation uses `SELECT ... FOR UPDATE` on the account rows.

A Redis lock has a TTL, and a TTL can expire in the middle of a transaction that
is merely slow. When it does, a second writer acquires the "same" lock while the
first is still writing, and both are convinced they hold it. A row lock is held
by the transaction itself and released exactly when it commits or rolls back —
there is no window where the lock and the work disagree.

Locks are always acquired one row at a time in ascending id order. A single
`whereIn(...)->lockForUpdate()` would depend on the query planner's scan order,
which is not a guarantee, and two transfers running in opposite directions
between the same pair of accounts would deadlock.

## The owner key type is configuration

`owner_id` is an integer, UUID, ULID or string column depending on
`balance.owner_key_type`.

One universal string column would have worked everywhere and been worse
everywhere: wider indexes for integer-keyed applications, and no type safety for
anyone. Since the choice is fixed per application and never changes at runtime,
it belongs in configuration, and `balance:install` detects it from your auth
model so most people never think about it.

## Amounts are integers

Everything is in minor units — cents, kopeks, satoshi. There are no floats in the
package and no decimal casting.

`0.1 + 0.2 !== 0.3` is not an acceptable property for money. The application
decides what a minor unit means for its currencies; the ledger only ever adds and
subtracts integers.

## Freezing blocks debits only

A frozen account still accepts credits. Only money leaving is stopped.

For fraud and AML work that is the useful semantics: you want to halt payouts
while an investigation runs, without bouncing incoming payments that are already
in flight and would otherwise be lost.
