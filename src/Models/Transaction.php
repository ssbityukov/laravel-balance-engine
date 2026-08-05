<?php

namespace Bityukov\BalanceEngine\Models;

use Bityukov\BalanceEngine\Enums\TransactionType;
use Bityukov\BalanceEngine\Models\Concerns\PreventsMutation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property TransactionType $type
 * @property string|null $idempotency_key
 * @property string|null $idempotency_fingerprint
 * @property string|null $reference_type
 * @property int|string|null $reference_id
 * @property int|null $reverses_id
 * @property int|null $parent_id
 * @property Carbon|null $expires_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
class Transaction extends Model
{
    use PreventsMutation;

    /**
     * The table carries only created_at.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('balance.table_prefix').'transactions';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'meta' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function findByUuid(string $uuid): ?static
    {
        return static::query()->where('uuid', $uuid)->first();
    }

    /**
     * @return HasMany<Entry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(config('balance.models.entry'), 'transaction_id');
    }

    /**
     * @return BelongsTo<static, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(static::class, 'reverses_id');
    }

    /**
     * @return HasOne<static, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(static::class, 'reverses_id');
    }

    /**
     * A capture or release transaction belongs to the reserve transaction it
     * draws down. Reservation state is derived from this parent/children chain
     * rather than stored — there is no reservations table.
     *
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
