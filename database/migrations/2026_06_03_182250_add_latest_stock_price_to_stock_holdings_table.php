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
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->foreignId('latest_stock_price_id')
                ->nullable()
                ->after('latest_quote_id')
                ->constrained('stock_prices')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropForeign(['latest_stock_price_id']);
            $table->dropColumn('latest_stock_price_id');
        });
    }
};
