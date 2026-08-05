<?php

namespace Bityukov\BalanceEngine\Ledger;

use Bityukov\BalanceEngine\Exceptions\IdempotencyKeyReused;
use Bityukov\BalanceEngine\Models\Transaction;

class IdempotencyGuard
{
    /**
     * Return the stored transaction for this key, or null when there is nothing
     * to replay. Throws when the key was used for a different operation.
     */
    public function replay(?string $key, string $fingerprint): ?Transaction
    {
        if ($key === null) {
            return null;
        }

        $existing = $this->find($key);

        if ($existing === null) {
            return null;
        }

        return $this->verify($existing, $key, $fingerprint);
    }

    /**
     * Same as replay(), but the transaction must exist. Used on the losing side
     * of a unique-index race, where the row is known to be there.
     */
    public function replayOrFail(string $key, string $fingerprint): Transaction
    {
        $existing = $this->find($key);

        if ($existing === null) {
            throw IdempotencyKeyReused::for($key);
        }

        return $this->verify($existing, $key, $fingerprint);
    }

    protected function find(string $key): ?Transaction
    {
        $model = config('balance.models.transaction');

        return $model::query()->where('idempotency_key', $key)->first();
    }

    protected function verify(Transaction $existing, string $key, string $fingerprint): Transaction
    {
        if ($existing->idempotency_fingerprint !== $fingerprint) {
            throw IdempotencyKeyReused::for($key);
        }

        return $existing;
    }
}
