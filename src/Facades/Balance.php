<?php

namespace Bityukov\BalanceEngine\Facades;

use Bityukov\BalanceEngine\BalanceManager;
use Bityukov\BalanceEngine\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Transaction deposit(Model $to, int $amount, ?string $currency = null, ?Model $reference = null, ?array $meta = null, ?string $idempotencyKey = null)
 * @method static Transaction withdraw(Model $from, int $amount, ?string $currency = null, ?Model $reference = null, ?array $meta = null, ?string $idempotencyKey = null)
 * @method static Transaction transfer(Model $from, Model $to, int $amount, ?string $currency = null, ?Model $reference = null, ?array $meta = null, ?string $idempotencyKey = null)
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
