<?php

namespace Bityukov\BalanceEngine\Models;

use Bityukov\BalanceEngine\Enums\AccountPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string|null $code
 * @property string $name
 * @property AccountPurpose $purpose
 * @property string $currency
 * @property int $balance
 * @property bool $allows_negative
 * @property Carbon|null $frozen_at
 * @property string|null $frozen_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Account extends Model
{
    protected $guarded = [];

    /**
     * Mirrors the column defaults from the accounts migration. Without this a
     * freshly created model reports null for balance and allows_negative until
     * it is refetched, because Eloquent never reads the schema's defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'name' => 'main',
        'purpose' => AccountPurpose::Available->value,
        'balance' => 0,
        'allows_negative' => false,
    ];

    public function getTable(): string
    {
        return config('balance.table_prefix').'accounts';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => AccountPurpose::class,
            'balance' => 'int',
            'allows_negative' => 'bool',
            'frozen_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(config('balance.models.entry'), 'account_id');
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where('purpose', AccountPurpose::Available);
    }

    public function scopeHold(Builder $query): void
    {
        $query->where('purpose', AccountPurpose::Hold);
    }
}
