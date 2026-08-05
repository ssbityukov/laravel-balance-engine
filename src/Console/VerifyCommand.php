<?php

namespace Bityukov\BalanceEngine\Console;

use Bityukov\BalanceEngine\Support\LedgerVerifier;
use Bityukov\BalanceEngine\Support\VerificationProblem;
use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    protected $signature = 'balance:verify {--account= : Verify a single account by id}';

    protected $description = 'Verify that the ledger invariants hold. Exits 1 on any discrepancy.';

    public function handle(LedgerVerifier $verifier): int
    {
        $accountId = $this->option('account');

        $problems = $verifier->verify($accountId !== null ? (int) $accountId : null);

        if ($problems === []) {
            $this->info('Ledger is sound: every invariant holds.');

            return self::SUCCESS;
        }

        $this->error(sprintf('Ledger verification found %d problem(s):', count($problems)));

        $this->table(
            ['Check', 'Detail'],
            array_map(
                fn (VerificationProblem $problem) => [$problem->check, $problem->detail],
                $problems,
            ),
        );

        $this->newLine();
        $this->warn('Do not run balance:rebuild before finding out why this happened.');

        return self::FAILURE;
    }
}
