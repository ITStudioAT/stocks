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
        Schema::table('stock_price_refresh_items', function (Blueprint $table) {
            $table->foreignId('selected_stock_price_id')
                ->nullable()
                ->after('selected_quote_id')
                ->constrained('stock_prices')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_price_refresh_items', function (Blueprint $table) {
            $table->dropForeign(['selected_stock_price_id']);
            $table->dropColumn('selected_stock_price_id');
        });
    }
};
