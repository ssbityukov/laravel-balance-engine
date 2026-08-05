<?php

namespace Bityukov\BalanceEngine\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\select;

class InstallCommand extends Command
{
    protected $signature = 'balance:install';

    protected $description = 'Publish the Balance Engine config and migrations, detecting your owner key type.';

    public function handle(): int
    {
        $model = $this->ownerModel();

        $keyType = $model !== null
            ? $this->detectOwnerKeyType($model)
            : (string) select(
                label: 'Which key type do your account owners use?',
                options: ['int', 'uuid', 'ulid', 'string'],
                default: 'int',
            );

        $this->callSilently('vendor:publish', ['--tag' => 'balance-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'balance-migrations']);

        $this->writeOwnerKeyType($keyType);

        $this->components->info('Balance Engine installed.');

        if ($model !== null) {
            $this->line("  Detected owner key type [{$keyType}] from [{$model}].");
        }

        $this->line('  Config published to config/balance.php');
        $this->line('  Next: php artisan migrate');
        $this->newLine();
        $this->warn('  Register a morph map so the ledger never stores raw class names:');
        $this->line("    Relation::enforceMorphMap(['user' => User::class]);");

        return self::SUCCESS;
    }

    /**
     * The ulid check comes first deliberately. HasUlids has been composed from
     * HasUuids in past Laravel versions, and if it ever is again, checking uuid
     * first would report every ulid model as uuid. Checking the narrower trait
     * first is correct either way.
     *
     * @param  class-string  $model
     */
    public function detectOwnerKeyType(string $model): string
    {
        $traits = class_uses_recursive($model);

        if (in_array(HasUlids::class, $traits, true)) {
            return 'ulid';
        }

        if (in_array(HasUuids::class, $traits, true)) {
            return 'uuid';
        }

        $instance = new $model;

        return $instance instanceof Model && $instance->getKeyType() === 'int' ? 'int' : 'string';
    }

    /**
     * @return class-string|null
     */
    protected function ownerModel(): ?string
    {
        $model = config('auth.providers.users.model');

        return is_string($model) && class_exists($model) ? $model : null;
    }

    protected function writeOwnerKeyType(string $keyType): void
    {
        $path = config_path('balance.php');

        if (! File::exists($path)) {
            return;
        }

        // Matches the whole value, not just a quoted literal: the shipped config
        // reads the key type from an environment variable, so a pattern looking
        // for '...' alone silently matches nothing and the detection is lost.
        File::put($path, (string) preg_replace(
            "/'owner_key_type'\s*=>\s*[^,]+,/",
            "'owner_key_type' => '{$keyType}',",
            File::get($path),
        ));
    }
}
