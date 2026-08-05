# Recipes

Every snippet here is copied from `tests/Feature/DocumentationTest.php` and runs
on each CI build.

## Marketplace: reserve at checkout, capture on shipping

Hold the buyer's money at checkout so it cannot be spent elsewhere, and pay the
seller only once the goods are on their way.

```php
$reservation = Balance::reserve(
    from: $buyer,
    amount: 6_000,
    expiresAt: now()->addMinutes(30),
);

// Shipped: the seller gets paid.
$reservation->capture(to: $seller);
```

The buyer's available balance drops at reserve time, not at capture time, so the
same money cannot be spent twice while the order is in flight.

Checkout and shipping are different requests. Store the uuid on the order and
load the reservation back when the goods go out:

```php
// Checkout request.
$uuid = Balance::reserve(from: $buyer, amount: 6_000)->uuid();

// Shipping request, later.
Balance::reservation($uuid)->capture(to: $seller);
```

Give the reservation an expiry and schedule the sweeper, so an abandoned checkout
returns the money on its own:

```php
Schedule::command('balance:expire-reservations')->everyFiveMinutes();
```

### Cancelled order

```php
$reservation->release();
```

The buyer is whole again and the reservation reads as `released`. Releasing works
on an expired reservation too, which is what lets the scheduled command clean up.

## Platform fee: two captures out of one reservation

A reservation can be captured more than once, to more than one destination, as
long as the total does not exceed what was held.

```php
$reservation = Balance::reserve(from: $buyer, amount: 10_000);

$reservation->capture(to: $seller, amount: 9_000);
$reservation->capture(to: $platform, amount: 1_000);
```

The reservation closes as `captured` once nothing remains. Both movements are
ordinary ledger transactions parented to the reserve, so the fee is as auditable
as the payment.

## Payment webhook: safe against retries

Gateways retry. Key the operation on the gateway's own event id and a repeat is
free:

```php
$eventId = 'evt_1PxyzABC';

Balance::deposit(
    to: $user,
    amount: 10_000,
    idempotencyKey: "stripe:{$eventId}",
    meta: ['gateway' => 'stripe'],
);
```

Call it twice and the money lands once; the second call returns the transaction
the first one wrote. This holds even when two workers process the same webhook
simultaneously, because a unique index backs it rather than a pre-flight check.

If the same key arrives with a *different* amount or a different account, the
call throws `IdempotencyKeyReused`. That is deliberate: quietly returning the
stored transaction would tell the caller it had performed something it had not.

## Bonus balance: spend the bonus first

Keep promotional money in a named account so it is never confused with real
funds, and drain it before touching the main balance:

```php
Balance::deposit(to: $user->balanceAccount('bonus'), amount: 2_000);

$price = 3_000;
$fromBonus = min($price, $user->balanceAmount('bonus'));

if ($fromBonus > 0) {
    Balance::withdraw(from: $user->balanceAccount('bonus'), amount: $fromBonus);
}

if ($price > $fromBonus) {
    Balance::withdraw(from: $user, amount: $price - $fromBonus);
}
```

Both accounts belong to the same owner and report separately, so
`$user->balanceAmount()` and `$user->balanceAmount('bonus')` never bleed into
each other and a refund can go back to whichever one it came from.

## Chargeback: reverse the deposit

```php
Balance::reverse($deposit, meta: ['reason' => 'chargeback']);
```

Nothing is deleted. The original deposit and its mirror both stay in the history,
and the `meta` explains why.

### When the money is already gone

A reversal obeys the same rules as any other operation, so it fails if the money
has since been spent:

```php
Balance::transfer(from: $alice, to: $bob, amount: 10_000);

// Throws InsufficientFunds: there is nothing left to take back.
Balance::reverse($deposit, meta: ['reason' => 'chargeback']);
```

This is the right outcome, not an obstacle. The alternative is pushing the
account into a negative balance it never agreed to. What to do instead is a
business decision the ledger should not make for you — chase the recipient,
absorb it on a platform account, or open a debt on an account you have flagged
`allows_negative` on purpose.

## Currency exchange: two operations, never one transfer

Accounts hold one currency and a transaction cannot span two, so there is no
cross-currency transfer. An exchange is a withdrawal and a deposit, at a rate
your application owns:

```php
$rate = 0.9;
$euros = (int) round(5_000 * $rate);

Balance::withdraw(from: $user, amount: 5_000, currency: 'USD');
Balance::deposit(to: $user->balanceAccount('main', 'EUR'), amount: $euros);
```

The dollars go back to the system account and the euros come out of it, so each
currency's books balance on their own. Rates and spreads stay in your
application, where they can be logged, versioned and disputed — the ledger only
records what was actually moved.

Wrap the pair in your own transaction if you need them to succeed or fail
together, but see the retry note in the README first: an outer transaction turns
the package's own retries into savepoints.
