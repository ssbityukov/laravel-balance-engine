<?php

namespace Bityukov\BalanceEngine\Tests\Fixtures;

use Bityukov\BalanceEngine\Concerns\HasBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The fixture's own key type follows balance.owner_key_type.
 *
 * Without this the suite runs an integer-keyed owner against a ledger
 * configured for uuid or ulid owners, which no real application can do. MySQL
 * tolerates it — char(n) there accepts "1" and does not pad — so the mismatch
 * looks fine and proves nothing. PostgreSQL does not: a native uuid column
 * rejects "1", and char(26) blank-pads it to 26 characters so it no longer
 * equals what was written.
 */
class User extends Model
{
    use HasBalance;

    protected $table = 'users';

    protected $guarded = [];

    public static function ownerKeyType(): string
    {
        return (string) config('balance.owner_key_type', 'int');
    }

    public function getKeyType(): string
    {
        return static::ownerKeyType() === 'int' ? 'int' : 'string';
    }

    public function getIncrementing(): bool
    {
        return static::ownerKeyType() === 'int';
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->getIncrementing() || $user->getKey() !== null) {
                return;
            }

            $user->setAttribute($user->getKeyName(), match (static::ownerKeyType()) {
                'ulid' => strtolower((string) Str::ulid()),
                default => (string) Str::uuid(),
            });
        });
    }
}
