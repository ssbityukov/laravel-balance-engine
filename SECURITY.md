# Security Policy

## Supported versions

The latest released 1.x line receives security fixes.

## Reporting a vulnerability

Please do not open a public issue for a security problem in a package that moves
money. Email **s.s.bityukov@gmail.com** with a description and, if you can, a
reproducing test.

Expect an acknowledgement within a few days, and a fix or a plan before any
public disclosure.

## What counts

Anything that lets an application lose or conjure money, in particular:

- a sequence of operations after which `SUM(balance_entries.amount) != 0`
- a way to drive an account negative without `allows_negative`
- a way to draw a reservation past what was put on hold
- a race between concurrent processes that either of the above survives
- a way to mutate or delete a `Transaction` or an `Entry` through the package

If you find one, `php artisan balance:verify` output from the affected database
is the most useful thing to include.
