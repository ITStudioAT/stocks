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
        Schema::create('stock_holding_intraday_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->date('trading_date');
            $table->unsignedTinyInteger('sample_index');
            $table->unsignedBigInteger('source_stock_price_id')->nullable();
            $table->decimal('price', 20, 8);
            $table->char('currency', 3)->nullable();
            $table->timestamp('as_of')->index();
            $table->string('source_name')->nullable();
            $table->string('price_type')->nullable();
            $table->timestamps();

            $table->unique(['stock_holding_id', 'trading_date', 'sample_index'], 'holding_intraday_sample_unique');
            $table->index(['stock_holding_id', 'trading_date'], 'holding_intraday_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_holding_intraday_prices');
    }
};
