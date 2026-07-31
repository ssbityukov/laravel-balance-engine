<?php

use Bityukov\BalanceEngine\Support\OwnerKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            OwnerKey::morphs($table, 'owner');
            $table->string('code')->nullable();
            $table->string('name')->default('main');
            $table->string('purpose')->default('available');
            $table->string('currency', 10);
            $table->bigInteger('balance')->default(0);
            $table->boolean('allows_negative')->default(false);
            $table->timestamp('frozen_at')->nullable();
            $table->string('frozen_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['owner_type', 'owner_id', 'name', 'purpose', 'currency'],
                'balance_accounts_owner_unique'
            );
            $table->unique(['code', 'currency'], 'balance_accounts_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('balance.table_prefix').'accounts';
    }
};
