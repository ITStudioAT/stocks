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
            $table->decimal('start_price', 20, 8)->nullable()->after('flatex_price');
            $table->decimal('end_price', 20, 8)->nullable()->after('start_price');
            $table->decimal('end_price_24', 20, 8)->nullable()->after('end_price');
            $table->decimal('end_price_48', 20, 8)->nullable()->after('end_price_24');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropColumn([
                'start_price',
                'end_price',
                'end_price_24',
                'end_price_48',
            ]);
        });
    }
};
