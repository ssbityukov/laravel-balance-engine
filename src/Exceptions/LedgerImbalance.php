<?php

namespace Bityukov\BalanceEngine\Exceptions;

use Bityukov\BalanceEngine\Support\VerificationProblem;

/**
 * Never thrown by balance:verify itself, which prints a report and returns an
 * exit code. This exists for applications calling the verifier from their own
 * code that would rather fail loudly.
 */
class LedgerImbalance extends BalanceException
{
    /**
     * @param  array<int, VerificationProblem>  $problems
     */
    public static function from(array $problems): static
    {
        $lines = array_map(
            fn (VerificationProblem $problem) => "[{$problem->check}] {$problem->detail}",
            $problems,
        );

        return new static("Ledger verification failed:\n".implode("\n", $lines));
    }
}
