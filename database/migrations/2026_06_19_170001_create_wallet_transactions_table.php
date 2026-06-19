<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWalletTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->decimal('amount', 20, 4);
            $table->decimal('balance_after', 20, 4);
            $table->string('idempotency_key', 255);
            $table->string('reference', 255);
            $table->timestamps();

            $table->foreign('wallet_id')->references('id')->on('wallets');
            $table->index('wallet_id');
            $table->unique(['wallet_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wallet_transactions');
    }
}
