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
        Schema::table('stock_ai_researches', function (Blueprint $table) {
            $table->json('calculation_snapshot')->nullable()->after('developments');
            $table->json('calculated_events')->nullable()->after('calculation_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_ai_researches', function (Blueprint $table) {
            $table->dropColumn(['calculation_snapshot', 'calculated_events']);
        });
    }
};
