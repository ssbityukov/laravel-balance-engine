<?php

namespace Bityukov\BalanceEngine\Enums;

enum AccountPurpose: string
{
    case Available = 'available';
    case Hold = 'hold';
}
