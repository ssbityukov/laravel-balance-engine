<?php

namespace Bityukov\BalanceEngine\Models\Concerns;

use Bityukov\BalanceEngine\Exceptions\ImmutableRecord;
use Illuminate\Database\Eloquent\Model;

trait PreventsMutation
{
    public static function bootPreventsMutation(): void
    {
        static::updating(function (Model $model): void {
            throw ImmutableRecord::for($model::class, 'update');
        });

        static::deleting(function (Model $model): void {
            throw ImmutableRecord::for($model::class, 'delete');
        });
    }
}
