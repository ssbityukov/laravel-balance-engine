<?php

namespace Bityukov\BalanceEngine\Tests\Feature;

class OwnerKeyStringTest extends OwnerKeyTypeTestCase
{
    protected function keyType(): string
    {
        return 'string';
    }

    protected function expectedColumnType(): array
    {
        return ['string', 36];
    }
}
