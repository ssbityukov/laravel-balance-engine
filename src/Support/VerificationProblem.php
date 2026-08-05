<?php

namespace Bityukov\BalanceEngine\Support;

final readonly class VerificationProblem
{
    public function __construct(
        public string $check,
        public string $detail,
    ) {}
}
