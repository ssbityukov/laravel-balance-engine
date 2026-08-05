<?php

use Bityukov\BalanceEngine\Console\InstallCommand;
use Bityukov\BalanceEngine\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

it('detects an integer key model', function () {
    expect(app(InstallCommand::class)->detectOwnerKeyType(User::class))->toBe('int');
});

it('detects a uuid key model', function () {
    $model = new class extends Model
    {
        use HasUuids;

        protected $table = 'users';
    };

    expect(app(InstallCommand::class)->detectOwnerKeyType($model::class))->toBe('uuid');
});

it('detects a ulid key model', function () {
    $model = new class extends Model
    {
        use HasUlids;

        protected $table = 'users';
    };

    expect(app(InstallCommand::class)->detectOwnerKeyType($model::class))->toBe('ulid');
});

it('falls back to string for a non-integer key without either trait', function () {
    $model = new class extends Model
    {
        protected $table = 'users';

        protected $keyType = 'string';
    };

    expect(app(InstallCommand::class)->detectOwnerKeyType($model::class))->toBe('string');
});

it('publishes the config file', function () {
    File::delete(config_path('balance.php'));

    $this->artisan('balance:install')->assertExitCode(0);

    expect(File::exists(config_path('balance.php')))->toBeTrue();
});

it('writes the detected key type into the published config', function () {
    File::delete(config_path('balance.php'));

    $this->artisan('balance:install')->assertExitCode(0);

    expect(File::get(config_path('balance.php')))
        ->toContain("'owner_key_type' => 'int'");
});

it('publishes migrations', function () {
    $this->artisan('balance:install')->assertExitCode(0);

    expect(File::glob(database_path('migrations/*create_balance_accounts_table.php')))
        ->not->toBeEmpty();
});

it('explains what it detected and why', function () {
    // One expectation, not two. Each expectsOutputToContain is matched against a
    // single write and the first matching one wins, so two substrings that share
    // an output line can never both be satisfied.
    $this->artisan('balance:install')
        ->expectsOutputToContain('Detected owner key type [int] from ['.User::class.']')
        ->assertExitCode(0);
});

it('tells the user to register a morph map', function () {
    $this->artisan('balance:install')
        ->expectsOutputToContain('enforceMorphMap')
        ->assertExitCode(0);
});

afterEach(function () {
    File::delete(config_path('balance.php'));

    foreach (File::glob(database_path('migrations/*balance*')) as $file) {
        File::delete($file);
    }
});
