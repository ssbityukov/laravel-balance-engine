<?php

namespace Bityukov\BalanceEngine\Tests\Feature;

class OwnerKeyIntTest extends OwnerKeyTypeTestCase
{
    protected function keyType(): string
    {
        return 'int';
    }

    protected function expectedColumnType(): array
    {
        return ['bigInteger', null];
    }
}
