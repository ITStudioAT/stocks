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
        Schema::table('stock_holding_intraday_prices', function (Blueprint $table) {
            $table->unsignedInteger('sample_index')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holding_intraday_prices', function (Blueprint $table) {
            $table->unsignedTinyInteger('sample_index')->change();
        });
    }
};
