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
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('amount');
            $table->string('currency', 10);
            $table->bigInteger('balance_after');
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'id'], 'balance_entries_account_index');

            $table->foreign('transaction_id')
                ->references('id')->on(config('balance.table_prefix').'transactions');
            $table->foreign('account_id')
                ->references('id')->on(config('balance.table_prefix').'accounts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('balance.table_prefix').'entries';
    }
};
