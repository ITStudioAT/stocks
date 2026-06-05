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
        Schema::table('index_watch_items', function (Blueprint $table) {
            $table->decimal('start_price', 20, 8)->nullable()->after('currency');
            $table->decimal('latest_price', 20, 8)->nullable()->after('start_price');
            $table->decimal('last_price', 20, 8)->nullable()->after('latest_price');
            $table->decimal('latest_price_change_pct', 12, 6)->nullable()->after('last_price');
            $table->timestamp('latest_price_as_of')->nullable()->after('latest_price_change_pct');
            $table->string('latest_price_source')->nullable()->after('latest_price_as_of');
            $table->string('trading_times')->nullable()->after('latest_price_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('index_watch_items', function (Blueprint $table) {
            $table->dropColumn([
                'start_price',
                'latest_price',
                'last_price',
                'latest_price_change_pct',
                'latest_price_as_of',
                'latest_price_source',
                'trading_times',
            ]);
        });
    }
};
