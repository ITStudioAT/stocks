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
            $table->string('preferred_venue')->nullable()->after('trading_times');
            $table->string('preferred_mic')->nullable()->after('preferred_venue');
            $table->string('preferred_source_key')->nullable()->after('preferred_mic');
            $table->foreignId('latest_quote_id')->nullable()->after('preferred_source_key')->constrained('stock_price_quotes')->nullOnDelete();
            $table->string('price_status')->nullable()->after('latest_quote_id');
            $table->string('latest_price_type')->nullable()->after('price_status');
            $table->decimal('price_spread_pct', 12, 6)->nullable()->after('latest_price_type');
            $table->timestamp('source_verified_at')->nullable()->after('price_spread_pct');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropForeign(['latest_quote_id']);
            $table->dropColumn([
                'preferred_venue',
                'preferred_mic',
                'preferred_source_key',
                'latest_quote_id',
                'price_status',
                'latest_price_type',
                'price_spread_pct',
                'source_verified_at',
            ]);
        });
    }
};
