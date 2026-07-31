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
            $table->unsignedBigInteger('hold_account_id');
            $table->bigInteger('amount');
            $table->bigInteger('captured_amount')->default(0);
            $table->bigInteger('released_amount')->default(0);
            $table->string('status')->default('open');
            $table->timestamp('expires_at')->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'balance_reservations_expiry_index');

            $table->foreign('transaction_id')
                ->references('id')->on(config('balance.table_prefix').'transactions');
            $table->foreign('account_id')
                ->references('id')->on(config('balance.table_prefix').'accounts');
            $table->foreign('hold_account_id')
                ->references('id')->on(config('balance.table_prefix').'accounts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('balance.table_prefix').'reservations';
    }
};
