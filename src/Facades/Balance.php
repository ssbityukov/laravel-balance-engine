<?php

namespace Bityukov\BalanceEngine\Facades;

use Bityukov\BalanceEngine\BalanceManager;
use Bityukov\BalanceEngine\Ledger\Reservation;
use Bityukov\BalanceEngine\Models\Account;
use Bityukov\BalanceEngine\Models\Transaction;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Transaction deposit(Model $to, int $amount, ?string $currency = null, ?Model $reference = null, ?array $meta = null, ?string $idempotencyKey = null)
 * @method static Transaction withdraw(Model $from, int $amount, ?string $currency = null, ?Model $reference = null, ?array $meta = null, ?string $idempotencyKey = null)
 * @method static Transaction transfer(Model $from, Model $to, int $amount, ?string $currency = null, ?Model $reference = null, ?array $meta = null, ?string $idempotencyKey = null)
 * @method static Reservation reserve(Model $from, int $amount, ?string $currency = null, ?DateTimeInterface $expiresAt = null, ?Model $reference = null, ?array $meta = null, ?string $idempotencyKey = null)
 * @method static Transaction capture(Reservation $reservation, Model $to, ?int $amount = null)
 * @method static Transaction release(Reservation $reservation, ?int $amount = null)
 * @method static Transaction reverse(Transaction $transaction, ?array $meta = null)
 * @method static Account freeze(Model $account, ?string $reason = null)
 * @method static Account unfreeze(Model $account)
 *
 * @see BalanceManager
 */
class Balance extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'balance';
    }
}
