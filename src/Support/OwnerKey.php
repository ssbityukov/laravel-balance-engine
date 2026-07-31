<?php

namespace Bityukov\BalanceEngine\Support;

use Illuminate\Database\Schema\Blueprint;

class OwnerKey
{
    /**
     * Add nullable polymorphic owner columns using the configured key type.
     */
    public static function morphs(Blueprint $table, string $name): void
    {
        match (config('balance.owner_key_type')) {
            'uuid' => $table->nullableUuidMorphs($name),
            'ulid' => $table->nullableUlidMorphs($name),
            'string' => static::stringMorphs($table, $name),
            default => $table->nullableMorphs($name),
        };
    }

    protected static function stringMorphs(Blueprint $table, string $name): void
    {
        $table->string("{$name}_type")->nullable();
        $table->string("{$name}_id", 36)->nullable();
        $table->index(["{$name}_type", "{$name}_id"]);
    }
}
