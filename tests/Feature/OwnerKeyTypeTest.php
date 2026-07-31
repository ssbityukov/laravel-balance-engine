<?php

use Illuminate\Support\Facades\Schema;

it('builds int owner columns by default', function () {
    expect(Schema::getColumnType('balance_accounts', 'owner_id'))
        ->toContain('int');
});
