<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type');
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('idempotency_fingerprint', 64)->nullable();
            $table->nullableMorphs('reference');
            $table->unsignedBigInteger('reverses_id')->nullable()->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('parent_id', 'balance_transactions_parent_index');
            $table->index('expires_at', 'balance_transactions_expiry_index');

            $table->foreign('reverses_id')->references('id')->on($this->table());
            $table->foreign('parent_id')->references('id')->on($this->table());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('balance.table_prefix').'transactions';
    }
};
