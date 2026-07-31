<?php

use Bityukov\BalanceEngine\BalanceEngineServiceProvider;

it('registers the package provider', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(BalanceEngineServiceProvider::class);
});

it('merges the package config', function () {
    expect(config('balance.table_prefix'))->toBe('balance_');
});
