<?php

namespace Bityukov\BalanceEngine\Tests\Feature;

class OwnerKeyUlidTest extends OwnerKeyTypeTestCase
{
    protected function keyType(): string
    {
        return 'ulid';
    }

    protected function expectedColumnType(): array
    {
        return ['char', 26];
    }
}
