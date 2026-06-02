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
            $table->decimal('latest_price', 20, 6)->nullable()->after('currency');
            $table->timestamp('latest_price_fetched_at')->nullable()->after('latest_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropColumn(['latest_price', 'latest_price_fetched_at']);
        });
    }
};
