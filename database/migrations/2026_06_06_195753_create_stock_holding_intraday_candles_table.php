<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_holding_intraday_candles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->date('trading_date');
            $table->string('interval', 8);
            $table->timestamp('as_of')->index();
            $table->bigInteger('timestamp')->nullable();
            $table->integer('gmtoffset')->nullable();
            $table->string('datetime')->nullable();
            $table->decimal('open', 20, 8)->nullable();
            $table->decimal('high', 20, 8)->nullable();
            $table->decimal('low', 20, 8)->nullable();
            $table->decimal('close', 20, 8)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('source_key')->default('eodhd_intraday');
            $table->string('source_name')->default('EODHD intraday');
            $table->text('source_url')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['stock_holding_id', 'interval', 'as_of'], 'holding_intraday_candle_unique');
            $table->index(['stock_holding_id', 'trading_date', 'interval'], 'holding_intraday_candle_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_holding_intraday_candles');
    }
};
