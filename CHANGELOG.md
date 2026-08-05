# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/).

## v1.0.1 - 2026-08-05

Use this rather than v1.0.0.

v1.0.0 was published to Packagist from a commit whose dependency
constraints could not be resolved: it required php ^8.3 while the test
toolchain needed ^8.4, and illuminate ^12.0|^13.0 while the supporting
testbench release only covered 13. Nothing about the ledger itself
differs.

### Changed

- Requires PHP 8.4 or newer and Laravel 13, which is what the suite
  actually runs against. The previous range was never installable on the
  parts it has now dropped.

### Fixed

- The readme states the PHP, Laravel and database requirements. It
  previously documented installation without saying what was needed.

## v1.0.0 - 2026-08-05

Initial release.

### Added

- Double-entry ledger: every operation writes at least two entries summing to zero
- `deposit`, `withdraw`, `transfer`, `reserve` / `capture` / `release`, `reverse`
- `freeze` and `unfreeze`, blocking debits while credits keep landing
- Reservations backed by a real hold account, with partial capture and expiry
- Row-level locking with deterministic ascending-id acquisition order
- Idempotency keys with fingerprint verification, backed by a unique index
- Immutable transactions and entries; corrections through reversal only
- Named accounts and per-currency accounts on a single owner
- Configurable owner key type: integer, UUID, ULID or string
- `balance:install`, which detects the owner key type from your auth model
- `Balance::reservation($uuid)`, for loading a reservation back in a later request
- Hold accounts are isolated: money enters only through `reserve()` and leaves
  only through `capture()` and `release()`
- Currencies are validated against the configured list before an account is opened
- `balance:verify`, checking seven ledger invariants and exiting 1 on any failure
- `balance:rebuild`, recomputing cached balances from the entries
- `balance:expire-reservations`, returning the remainder of elapsed reservations
- Events for every operation, all deferred until after commit
