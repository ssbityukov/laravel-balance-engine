# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/).

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
