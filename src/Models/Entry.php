<?php

namespace Bityukov\BalanceEngine\Models;

use Bityukov\BalanceEngine\Models\Concerns\PreventsMutation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $transaction_id
 * @property int $account_id
 * @property int $amount
 * @property string $currency
 * @property int $balance_after
 * @property Carbon|null $created_at
 */
class Entry extends Model
{
    use PreventsMutation;

    /**
     * The table carries only created_at.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('balance.table_prefix').'entries';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'int',
            'balance_after' => 'int',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        /** @var class-string<Transaction> $model */
        $model = config('balance.models.transaction');

        return $this->belongsTo($model, 'transaction_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        /** @var class-string<Account> $model */
        $model = config('balance.models.account');

        return $this->belongsTo($model, 'account_id');
    }
}
