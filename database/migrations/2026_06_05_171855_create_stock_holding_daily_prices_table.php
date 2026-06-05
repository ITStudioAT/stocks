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
        Schema::create('stock_holding_daily_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->date('trading_date');
            $table->decimal('open', 20, 8)->nullable();
            $table->decimal('high', 20, 8)->nullable();
            $table->decimal('low', 20, 8)->nullable();
            $table->decimal('close', 20, 8)->nullable();
            $table->decimal('adjusted_close', 20, 8)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('source_key')->default('eodhd_eod')->index();
            $table->string('source_name')->default('EODHD EOD');
            $table->text('source_url');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['stock_holding_id', 'trading_date']);
            $table->index(['stock_holding_id', 'trading_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_holding_daily_prices');
    }
};
