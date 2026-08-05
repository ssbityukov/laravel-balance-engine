<?php

use Bityukov\BalanceEngine\Tests\ConcurrencyTestCase;
use Bityukov\BalanceEngine\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(ConcurrencyTestCase::class)->in('Concurrency');
