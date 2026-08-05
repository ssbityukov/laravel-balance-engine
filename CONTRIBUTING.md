# Contributing

## Running the suite

```bash
composer test        # sqlite, the concurrency suite skips
composer analyse     # phpstan level 8 over src
composer format      # pint
```

Concurrency tests need a database with real row-level locking. They skip on
SQLite with an explicit message rather than passing quietly:

```bash
mysql -u root -e 'create database if not exists balance_engine_test'

DB_CONNECTION=mysql DB_DATABASE=balance_engine_test DB_USERNAME=root \
  composer test:concurrency
```

The whole suite also runs against MySQL and PostgreSQL, and against each owner
key type:

```bash
BALANCE_OWNER_KEY_TYPE=uuid composer test
```

## What a change needs

**A test that fails without it.** For a bugfix, write the failing test first and
say in the pull request what it printed before the fix.

**Proof the test is not vacuous.** This package has twice shipped tests that
passed while asserting nothing, because SQLite emits no `for update` clause and
quotes identifiers differently from MySQL. If you add a test that watches
queries or checks a derived value, break the implementation on purpose, confirm
the test goes red, and put that in the pull request.

**Documentation that executes.** Every code block in `README.md` and `docs/` is
copied from `tests/Feature/DocumentationTest.php`. If you change an example,
change the test it came from.

## Things that are deliberate

Before proposing a change to any of these, read `docs/concepts.md` — each was
chosen over the obvious alternative for a reason:

- Records are immutable. Corrections go through `reverse()`, never an update.
- Reservation state is derived, never stored. There is no status column.
- Money reaches a hold account only through `reserve()` and leaves only through
  `capture()` and `release()`.
- Locks are taken in the database, one row at a time, in ascending id order.
- Amounts are integers in minor units. No floats, anywhere.

## Style

Pint with the project's `pint.json`; run `composer format` before pushing.
PHPStan runs at level 8 over `src`. Prefer closing an error with an annotation
over adding it to `ignoreErrors` — the two ignores that remain are documented in
`phpstan.neon` and explain why they cannot be closed.
