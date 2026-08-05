<?php

namespace Bityukov\BalanceEngine\Console;

use Bityukov\BalanceEngine\Ledger\AccountLocker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildCommand extends Command
{
    protected $signature = 'balance:rebuild {--account= : Rebuild a single account by id}';

    protected $description = 'Recompute cached account balances from the ledger entries.';

    public function handle(AccountLocker $locker): int
    {
        $accountId = $this->option('account');

        $ids = config('balance.models.account')::query()
            ->when($accountId !== null, fn ($query) => $query->whereKey((int) $accountId))
            ->orderBy('id')
            ->pluck('id');

        $changed = 0;

        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($locker, $id): int {
                $account = $locker->lock([$id])[$id];

                $ledger = (int) config('balance.models.entry')::query()
                    ->where('account_id', $id)
                    ->sum('amount');

                if ($account->balance === $ledger) {
                    return 0;
                }

                $this->line("  account [{$id}]: {$account->balance} -> {$ledger}");

                $account->forceFill(['balance' => $ledger])->save();

                return 1;
            });
        }

        $this->info("Rebuilt {$ids->count()} account(s), corrected {$changed}.");

        return self::SUCCESS;
    }
}
