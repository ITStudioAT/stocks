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
            $table->string('latest_price_source')->nullable()->after('latest_price_fetched_at');
            $table->text('latest_price_source_url')->nullable()->after('latest_price_source');
            $table->string('latest_price_as_of')->nullable()->after('latest_price_source_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropColumn(['latest_price_source', 'latest_price_source_url', 'latest_price_as_of']);
        });
    }
};
