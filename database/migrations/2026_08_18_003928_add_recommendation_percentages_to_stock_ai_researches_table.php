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
            $table->unsignedTinyInteger('recommendation_buy_pct')->nullable()->after('recommendation');
            $table->unsignedTinyInteger('recommendation_hold_pct')->nullable()->after('recommendation_buy_pct');
            $table->unsignedTinyInteger('recommendation_sell_pct')->nullable()->after('recommendation_hold_pct');
        });
    }
};
